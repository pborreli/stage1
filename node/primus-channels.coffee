require 'colors'

redis  = require 'redis'

@PrimusChannels =
    name: 'channels'
    client: (primus, options) ->
        primus.unsubscribe = (channel) ->
                primus.write action: 'unsubscribe', channel: channel

        primus.subscribe = (channel, token = null) ->
            subscribe = (token) -> primus.write action: 'subscribe', channel: channel, token: token

            if token? or not channel.match(options.privatePattern || /^(private|presence)\-/)
                subscribe token
            else
                $.post options.auth_url, { channel: channel }, (response) ->
                    response = JSON.parse(response)
                    if response.token
                        subscribe response.token

    server: (primus, options) ->
        primus.channels = {}
        primus.redis = redis.createClient()

        primus.Spark::subscribe = (channel, token) ->
            unless primus.channels[channel]?
                primus.channels[channel] = new Channel(channel, primus.redis, options)

            primus.channels[channel].subscribe this, token

        primus.Spark::unsubscribe = (channel) ->
            if channel?
                if primus.channels[channel]?
                    primus.channels[channel].unsubscribe this
            else
                for channel of primus.channels
                    primus.channels[channel].unsubscribe this

        primus.on 'connection', (spark) ->
            spark.write id: spark.id
            spark.on 'data', (data) ->
                return unless data.channel

                if data.action == 'subscribe'
                    spark.subscribe data.channel, data.token
                if data.action == 'unsubscribe'
                    spark.unsubscribe data.channel

        primus.on 'disconnection', (spark) ->
            spark.unsubscribe()

class Channel
    constructor: (@name, @redis, @options) ->
        @sparks = []
        @isPrivate = @name.match(@options.privatePattern || /^(private|presence)\-/)

    write: (message) ->
        for spark in @sparks
            spark.write message

    auth: (token, success, failure = ->) ->
        if @isPrivate and token != true
            @redis.sismember 'channel:auth:' + @name, token, (err, result) ->
                throw err if err
                if result then success() else failure()
        else
            success()

    has: (spark) ->
        for s in @sparks
            return true if s.id == spark.id

        return false

    subscribe: (spark, token) ->
        @auth token,
            =>
                if @has spark
                    console.warn ('   spark#' + spark.id + ' was already subscribed to "' + @name + '"').yellow
                else 
                    @sparks.push spark
                    console.log ('   subscribed spark#' + spark.id + ' to channel "' + @name + '" (clients: ' + @sparks.length + ')').green

                # check if this is a meta channel
                @redis.smembers 'channel:routing:' + @name, (err, results) =>
                    throw err if err

                    if results.length > 0
                        for result in results
                            spark.subscribe result, true

            =>
                console.log ('   spark#' + spark.id + ' failed authorization for channel "' + @name + '"').red

    unsubscribe: (spark) ->
        if @has spark
            @sparks = (_ for _ in @sparks when _.id != spark.id)
            console.log ('   unsubscribed spark#' + spark.id + ' from channel "' + @name + '" (clients: ' + @sparks.length + ')').green