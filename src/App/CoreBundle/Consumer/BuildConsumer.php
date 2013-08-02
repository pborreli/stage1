<?php

namespace App\CoreBundle\Consumer;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Process\ProcessBuilder;

use App\CoreBundle\Entity\Build;

use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use OldSound\RabbitMqBundle\RabbitMq\Producer;
use PhpAmqpLib\Message\AMQPMessage;

use InvalidArgumentException;
use RuntimeException;
use Exception;

class BuildConsumer implements ConsumerInterface
{
    private $doctrine;

    private $producer;

    public function __construct(RegistryInterface $doctrine, Producer $producer)
    {
        $this->doctrine = $doctrine;
        $this->producer = $producer;
    }

    public function getDoctrine()
    {
        return $this->doctrine;
    }

    private function persistAndFlush($entity)
    {
        $em = $this->getDoctrine()->getManager();
        $em->persist($entity);
        $em->flush();
    }

    public function getPendingBuildsCount($project)
    {
        $buildRepo = $this->doctrine->getRepository('AppCoreBundle:Build');
        $qb = $buildRepo->createQueryBuilder('b');

        $query = $buildRepo->createQueryBuilder('b')
           ->select('count(b.id)')
            ->where('b.project = ?1')
            ->andWhere('b.status IN (?2)')
            ->setParameters([
                1 => $project->getId(),
                2 => [Build::STATUS_BUILDING, Build::STATUS_SCHEDULED]
            ])
            ->getQuery();

        try {
            return (int) $query->getSingleScalarResult();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function doBuild(Build $build)
    {
        $webUiDebugMode = false;

        $outputFile = '/tmp/stage1-build-output';

        $buildFile = function($type) { return '/tmp/stage1-build-'.$type; };

        if (file_exists($buildFile('output'))) {
            unlink($buildFile('output'));
        }

        if (!$webUiDebugMode) {
            $projectDir = realpath(__DIR__.'/../../../..');
            $builder = new ProcessBuilder([
                $projectDir.'/bin/build.sh',
                $build->getId(),
                $build->getRef(),
                $build->getHash(),
                $build->getProject()->getCloneUrl(),
                $build->getProject()->getOwner()->getAccessToken(),
                $build->getImageName()
            ]);
            $builder->setTimeout(0);

            $process = $builder->getProcess();
            $process->setCommandLine($process->getCommandLine().' > '.$buildFile('output').' 2>> '.$buildFile('output'));

            echo 'running '.$process->getCommandLine().PHP_EOL;
            $process->run();

            $build->setOutput(file_get_contents($buildFile('output')));
            $build->setExitCode($process->getExitCode());
            $build->setExitCodeText($process->getExitCodeText());

            if (!$process->isSuccessful()) {
                return false;
            }

            $buildInfo = explode(PHP_EOL, trim(file_get_contents($buildFile('info'))));

            if (count($buildInfo) !== 3) {
                throw new InvalidArgumentException('Malformed build info: '.var_export($buildInfo, true));
            }

            list($imageId, $containerId, $port) = $buildInfo;

            $build->setContainerId($containerId);
            $build->setImageId($imageId);
            $build->setUrl('http://stage1:'.$port);
        }

        $queryBuilder = $this->doctrine->getRepository('AppCoreBundle:Build')->createQueryBuilder('b');

        if (!$webUiDebugMode) {
            try {
                $previousBuild = $queryBuilder
                    ->select()
                    ->where($queryBuilder->expr()->eq('b.project', '?1'))
                    ->andWhere($queryBuilder->expr()->eq('b.ref', '?2'))
                    ->andWhere($queryBuilder->expr()->eq('b.status', '?3'))
                    ->setParameters([
                        1 => $build->getProject()->getId(),
                        2 => $build->getRef(),
                        3 => Build::STATUS_RUNNING,
                    ])
                    ->getQuery()
                    ->getSingleResult();

                $builder = new ProcessBuilder([$projectDir.'/bin/stop.sh', $previousBuild->getContainerId(), $previousBuild->getImageId()]);
                $process = $builder->getProcess();

                echo 'running '.$process->getCommandLine().PHP_EOL;

                $process->run();
            } catch (Exception $e) {
                // maybe we were the first build?
            }
        }

        $queryBuilder
            ->update()
            ->set('b.status', '?1')
            ->where($queryBuilder->expr()->eq('b.project', '?2'))
            ->andWhere($queryBuilder->expr()->eq('b.ref', '?3'))
            ->andWhere($queryBuilder->expr()->eq('b.status', '?4'))
            ->setParameters([
                1 => Build::STATUS_OBSOLETE,
                2 => $build->getProject()->getId(),
                3 => $build->getRef(),
                4 => Build::STATUS_RUNNING
            ])
            ->getQuery()
            ->execute();

        return true;
    }

    public function execute(AMQPMessage $message)
    {
        $body = json_decode($message->body);

        $buildRepo = $this->doctrine->getRepository('AppCoreBundle:Build');
        $build = $buildRepo->find($body->build_id);

        if (!$build) {
            throw new RuntimeException('Could not find Build#'.$body->build_id);
        }

        if (!$build->isScheduled()) {
            return true;
        }

        $build->setStatus(Build::STATUS_BUILDING);
        $this->persistAndFlush($build);

        $this->producer->publish(json_encode(['event' => 'build.started', 'data' => [
            'build' => [
                'id' => $build->getId()
            ],
            'project' => [
                'id' => $build->getProject()->getId(),
                'nb_pending_builds' => $this->getPendingBuildsCount($build->getProject())
            ]
        ]]));

        try {
            $res = $this->doBuild($build);
            $build->setStatus($res === false ? Build::STATUS_FAILED : Build::STATUS_RUNNING);
        } catch (Exception $e) {
            $build->setStatus(Build::STATUS_FAILED);
            $build->setMessage($e->getMessage());
        }

        $this->persistAndFlush($build);

        $this->producer->publish(json_encode(['event' => 'build.finished', 'data' => [
            'build' => [
                'id' => $build->getId(),
                'status' => $build->getStatus(),
                'status_label' => $build->getStatusLabel(),
                'status_label_class' => $build->getStatusLabelClass(),
                'url' => $build->getUrl(),
            ],
            'project' => [
                'id' => $build->getProject()->getId(),
                'nb_pending_builds' => $this->getPendingBuildsCount($build->getProject()),
            ]
        ]]));
    }
}