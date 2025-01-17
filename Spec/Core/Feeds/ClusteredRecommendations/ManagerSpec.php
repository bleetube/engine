<?php
declare(strict_types=1);

namespace Spec\Minds\Core\Feeds\ClusteredRecommendations;

use Exception;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Experiments\Manager as ExperimentsManager;
use Minds\Core\Feeds\ClusteredRecommendations\LegacyMySQLRepository;
use Minds\Core\Feeds\ClusteredRecommendations\Manager;
use Minds\Core\Feeds\ClusteredRecommendations\MySQLRepository;
use Minds\Core\Feeds\ClusteredRecommendations\RepositoryFactory;
use Minds\Core\Feeds\Elastic\ScoredGuid;
use Minds\Core\Feeds\FeedSyncEntity;
use Minds\Core\Feeds\Seen\Manager as SeenManager;
use Minds\Core\Recommendations\UserRecommendationsCluster;
use Minds\Entities\Entity;
use Minds\Entities\User;
use PhpSpec\ObjectBehavior;
use PhpSpec\Wrapper\Collaborator;
use Spec\Minds\Common\Traits\CommonMatchers;

class ManagerSpec extends ObjectBehavior
{
    use CommonMatchers;

    private Collaborator $entitiesBuilder;
    private Collaborator $userRecommendationsCluster;
    private Collaborator $seenManager;
    private Collaborator $repositoryFactory;
    private Collaborator $experimentsManager;

    public function let(
        UserRecommendationsCluster $userRecommendationsCluster,
        SeenManager $seenManager,
        EntitiesBuilder $entitiesBuilder,
        RepositoryFactory $repositoryFactory,
        ExperimentsManager $experimentsManager
    ) {
        $this->entitiesBuilder = $entitiesBuilder;
        $this->userRecommendationsCluster = $userRecommendationsCluster;
        $this->seenManager = $seenManager;
        $this->repositoryFactory = $repositoryFactory;
        $this->experimentsManager = $experimentsManager;

        $this->beConstructedWith(
            null,
            $this->entitiesBuilder,
            $this->userRecommendationsCluster,
            $this->seenManager,
            $this->repositoryFactory,
            $this->experimentsManager
        );
    }

    public function it_is_initializable(): void
    {
        $this->shouldBeAnInstanceOf(Manager::class);
    }

    /**
     * @param User $user
     * @param MySQLRepository $repository
     * @param Entity $entity
     * @return void
     * @throws Exception
     */
    public function it_should_get_list_of_recommendations_for_user(
        User $user,
        MySQLRepository $repository,
        Entity $entity
    ): void {
        $this->setUser($user);

        $this->experimentsManager->setUser($user)
            ->shouldBeCalledOnce();

        $this->experimentsManager->isOn('engine-2494-clustered-recs-v2')
            ->shouldBeCalled()
            ->willReturn(true);

        $this->seenManager->getIdentifier()
            ->shouldBeCalledOnce()
            ->willReturn("");

        $repository->getList(0, 12, [], true, "")
            ->shouldBeCalledOnce()
            ->willYield([
                (new ScoredGuid())
                    ->setGuid('123')
                    ->setType('activity')
                    ->setScore(1)
                    ->setOwnerGuid('123')
                    ->setTimestamp(0)
            ]);

        $repository->setUser($user);

        $this->repositoryFactory->getInstance(MySQLRepository::class)
            ->shouldBeCalledOnce()
            ->willReturn($repository);

        $entity->getGuid()
            ->shouldBeCalledOnce()
            ->willReturn("123");

        $entity->getOwnerGuid()
            ->shouldBeCalledOnce()
            ->willReturn("123");

        $entity->getUrn()
            ->shouldBeCalled()
            ->willReturn("");

        $this->entitiesBuilder->single('123')
            ->shouldBeCalledOnce()
            ->willReturn($entity);

        $this->getList(12, true)
            ->shouldContainAnInstanceOf(FeedSyncEntity::class);
    }

    /**
     * @param User $user
     * @param LegacyMySQLRepository $repository
     * @param Entity $entity
     * @return void
     * @throws Exception
     */
    public function it_should_get_legacy_list_of_recommendations_for_user(
        User $user,
        LegacyMySQLRepository $repository,
        Entity $entity
    ): void {
        $this->setUser($user);

        $this->experimentsManager->setUser($user)
            ->shouldBeCalledOnce();

        $this->experimentsManager->isOn('engine-2494-clustered-recs-v2')
            ->shouldBeCalled()
            ->willReturn(false);

        $this->seenManager->getIdentifier()
            ->shouldBeCalledOnce()
            ->willReturn("");

        $this->userRecommendationsCluster->calculateUserRecommendationsClusterId($user)
            ->shouldBeCalledOnce()
            ->willReturn(0);

        $repository->getList(0, 12, [], true, "")
            ->shouldBeCalledOnce()
            ->willYield([
                (new ScoredGuid())
                    ->setGuid('123')
                    ->setType('activity')
                    ->setScore(1)
                    ->setOwnerGuid('123')
                    ->setTimestamp(0)
            ]);

        $this->repositoryFactory->getInstance(LegacyMySQLRepository::class)
            ->shouldBeCalledOnce()
            ->willReturn($repository);

        $entity->getGuid()
            ->shouldBeCalledOnce()
            ->willReturn("123");

        $entity->getOwnerGuid()
            ->shouldBeCalledOnce()
            ->willReturn("123");

        $entity->getUrn()
            ->shouldBeCalled()
            ->willReturn("");

        $this->entitiesBuilder->single('123')
            ->shouldBeCalledOnce()
            ->willReturn($entity);

        $this->getList(12, true)
            ->shouldContainAnInstanceOf(FeedSyncEntity::class);
    }
}
