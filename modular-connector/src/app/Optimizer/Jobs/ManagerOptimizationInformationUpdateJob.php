<?php

namespace Modular\Connector\Optimizer\Jobs;

use Modular\Connector\Optimizer\AkismetCommentMeta;
use Modular\Connector\Optimizer\AutoDrafts;
use Modular\Connector\Optimizer\Events\ManagerOptimizationInformation;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\Connector\Optimizer\OptimizeOverheadTables;
use Modular\Connector\Optimizer\OrphanedCommentMeta;
use Modular\Connector\Optimizer\OrphanedPostMeta;
use Modular\Connector\Optimizer\OrphanedRelationshipData;
use Modular\Connector\Optimizer\OrphanedUserMeta;
use Modular\Connector\Optimizer\Pingbacks;
use Modular\Connector\Optimizer\Revisions;
use Modular\Connector\Optimizer\Spam;
use Modular\Connector\Optimizer\Trackbacks;
use Modular\Connector\Optimizer\Transients;
use Modular\Connector\Optimizer\TrashedPosts;
use Modular\Connector\Optimizer\UnapprovedComments;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class ManagerOptimizationInformationUpdateJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var
     */
    protected $payload; // 2 hour

    private $availableOptimizations = [
        'akismet_comment_meta' => AkismetCommentMeta::class,
        'auto_drafts' => AutoDrafts::class,
        'database_overhead' => OptimizeOverheadTables::class,
        'orphaned_comment_meta' => OrphanedCommentMeta::class,
        'orphaned_post_meta' => OrphanedPostMeta::class,
        'orphaned_relationship_data' => OrphanedRelationshipData::class,
        'orphaned_user_meta' => OrphanedUserMeta::class,
        'pingbacks' => Pingbacks::class,
        'revisions' => Revisions::class,
        'spam_trash_comments' => Spam::class,
        'trackbacks' => Trackbacks::class,
        'transients' => Transients::class,
        'trashed_posts' => TrashedPosts::class,
        'unapproved_comments' => UnapprovedComments::class,
    ];

    /**
     * @param string $mrid
     * @param $payload
     */
    public function __construct(string $mrid, $payload)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $payload = $this->payload;

        $optimizationSettings = data_get($payload, 'settings', []);
        $optimizations = Collection::make($this->availableOptimizations);

        $info = $optimizations->map(function ($optimization) use ($optimizationSettings) {
            /**
             * @var OptimizationInterface $optimization
             */
            $optimization = new $optimization($optimizationSettings);

            return $optimization->all();
        });

        event(new ManagerOptimizationInformation($this->mrid, $info->toArray()));
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
