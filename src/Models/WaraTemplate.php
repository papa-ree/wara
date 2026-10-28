<?php

namespace Bale\Wara\Models;

use Bale\Core\Traits\LogsActivity;
use Bale\Wara\Enums\ParameterFormat;
use Bale\Wara\Enums\TemplateCategory;
use Bale\Wara\Enums\TemplateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Template pesan milik guest (PRD §16).
 *
 * @property string $id
 * @property string $user_uuid
 * @property string $name
 * @property TemplateCategory $category
 * @property TemplateStatus $status
 * @property ParameterFormat $parameter_format
 * @property array $components
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class WaraTemplate extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    protected $table = 'wara_templates';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'category',
        'status',
        'parameter_format',
        'components',
        'rejection_reason',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'category' => TemplateCategory::class,
        'status' => TemplateStatus::class,
        'parameter_format' => ParameterFormat::class,
        'components' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => TemplateStatus::Draft->value,
        'parameter_format' => ParameterFormat::Positional->value,
    ];

    public const LOGGED_ATTRIBUTES = [
        'name',
        'category',
        'status',
        'parameter_format',
        'components',
        'rejection_reason',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(self::LOGGED_ATTRIBUTES)
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    /**
     * Pemilik template. Class user diambil dari config supaya package ini
     * tidak mengimpor `App\Models\User` secara statis.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function reviewer(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'reviewed_by', 'uuid');
    }

    /**
     * @param  Builder<WaraTemplate>  $query
     * @return Builder<WaraTemplate>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', TemplateStatus::Approved->value);
    }

    /**
     * @param  Builder<WaraTemplate>  $query
     * @return Builder<WaraTemplate>
     */
    public function scopeOwnedBy(Builder $query, string $userUuid): Builder
    {
        return $query->where('user_uuid', $userUuid);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isApproved(): bool
    {
        return $this->status->isApproved();
    }

    /**
     * Ringkasan body untuk daftar, tanpa memaksa view mengurai JSON.
     */
    public function bodyPreview(int $limit = 80): string
    {
        $body = (string) data_get($this->components, 'body.text', '');

        return Str::limit($body, $limit);
    }
}
