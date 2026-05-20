<?php

namespace Modular\Connector\Models;

use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Builder;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `redirects` table on the `modular` connection.
 *
 * Column names mirror the manager-side contract 1:1 except for `match`, which
 * is a MySQL reserved keyword (full-text search) — we store it as `match_type`
 * locally and translate at the sync boundary.
 *
 * Bulk sync paths in {@see \Modular\Connector\Services\RedirectService::sync()}
 * use the query builder directly to avoid per-row model events on mass
 * insert/upsert/delete; this model is intended for single-record reads,
 * cache hydration, and any future admin/CLI tooling.
 */
class Redirect extends Model
{
    public const MATCH_EXACT = 'exact';
    public const MATCH_REGEX = 'regex';

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $connection = 'modular';

    protected $table = 'redirects';

    protected $fillable = [
        'source_id',
        'source_pattern',
        'destination_url',
        'type',
        'match_type',
        'status',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'type' => 'integer',
    ];

    /**
     * Active rules only — matches the runtime filter in
     * RedirectService::buildRulesFromDatabase(). The status string is the
     * authoritative gate; only the exact value `enabled` activates the rule,
     * so unknown future values stay inactive by default.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * Exact-match rules (everything that is not flagged as regex).
     * Mirrors the runtime behaviour: only `regex` is treated as regex,
     * any other value falls back to exact matching.
     */
    public function scopeExact(Builder $query): Builder
    {
        return $query->where('match_type', '!=', self::MATCH_REGEX);
    }

    public function scopeRegex(Builder $query): Builder
    {
        return $query->where('match_type', self::MATCH_REGEX);
    }
}
