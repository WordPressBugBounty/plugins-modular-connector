<?php

namespace Modular\Connector\Optimizer\Jobs;

use Modular\Connector\Optimizer\AkismetCommentMeta;
use Modular\Connector\Optimizer\AutoDrafts;
use Modular\Connector\Optimizer\Events\ManagerOptimizationUpdated;
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
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class ManagerOptimizationProcessJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;

    public $uniqueFor = 3600;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var
     */
    protected $payload;

    /**
     * @var string
     */
    protected string $type;

    /**
     * @var string[]
     */
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
     * @param string $type
     */
    public function __construct(string $mrid, $payload, string $type)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
        $this->type = $type;
        $this->queue = 'optimizations';
    }

    /**
     * @return void
     */
    public function handle()
    {
        $type = $this->type;

        if (!in_array($type, array_keys($this->availableOptimizations))) {
            return;
        }

        $settings = data_get($this->payload, 'settings', []);
        $optimization = data_get($this->availableOptimizations, $type);
        $optimizationId = data_get($this->payload, 'optimizationId');

        Log::debug('Starting optimization ', [
            'type' => $type,
            'settings' => $settings,
        ]);

        /**
         * @var \Modular\Connector\Optimizer\Interfaces\OptimizationInterface $optimization
         */
        $optimization = new $optimization($settings);
        $result = $optimization->optimize();

        Log::debug('Finished optimization.', [
            'result' => $result,
        ]);

        event(
            new ManagerOptimizationUpdated(
                $this->mrid,
                array_merge($result, [
                    'type' => $type,
                    'optimization' => $optimizationId,
                ])
            )
        );
    }

    /**
     * @return string
     */
    public function uniqueId()
    {
        return sprintf('%s-%s', $this->type, $this->mrid);
    }
}
