<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\Helper;

class Comment extends Model
{
    protected $table = 'fcom_post_comments';

    protected $guarded = ['id'];

    protected $fillable = [
        'user_id',
        'post_id',
        'parent_id',
        'message',
        'message_rendered',
        'meta',
        'type',
        'content_type',
        'status',
        'is_sticky',
        'reactions_count',
        'created_at',
        'updated_at'
    ];

    protected $searchable = [
        'message'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->user_id)) {
                $model->user_id = get_current_user_id();
            }
            $model->type = 'comment';
        });

        static::deleting(function ($comment) {
            Media::where('sub_object_id', $comment->id)
                ->where('object_source', 'comment')
                ->update([
                    'is_active' => 0
                ]);

            $notifications = Notification::where('object_id', $comment->id)
                ->where('src_object_type', 'comment')
                ->get();

            foreach ($notifications as $notification) {
                $notification->delete();
            }

            $childComments = Comment::where('parent_id', $comment->id)
                ->where('post_id', $comment->post_id)
                ->get();

            foreach ($childComments as $childComment) {
                $childComment->delete();
            }
        });

        static::addGlobalScope('type', function ($builder) {
            $builder->where('type', 'comment');
        });
    }

    public function scopeSearchBy($query, $search)
    {
        if ($search) {
            $fields = $this->searchable;
            $query->where(function ($query) use ($fields, $search) {
                $query->where(array_shift($fields), 'LIKE', "%$search%");
                foreach ($fields as $field) {
                    $query->orWhere($field, 'LIKE', "$search%");
                }
            });
        }

        return $query;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'ID');
    }

    public function xprofile()
    {
        return $this->belongsTo(XProfile::class, 'user_id', 'user_id');
    }

    public function media()
    {
        return $this->hasOne(Media::class, 'sub_object_id', 'id');
    }

    public function post()
    {
        return $this->belongsTo(Feed::class, 'post_id', 'id')->withoutGlobalScopes();
    }

    public function reactions()
    {
        return $this->hasMany(Reaction::class, 'object_id', 'id')
            ->where('object_type', 'comment');
    }

    public function scopePendingCommentsByUser($query, $currentUserId, $space = null)
    {
        $user = User::find($currentUserId);
        $spaceId = $space ? $space->id : null;
        if ($user && $user->hasCommunityModeratorAccess()) {
            return $query->when(true, function($q) {
                $q->orWhere('status', 'pending');
            })->when($spaceId, function($q) use ($spaceId) {
                $q->whereHas('post.space', function($spaceQuery) use ($spaceId) {
                    $spaceQuery->where('id', $spaceId);
                });
            });
        }

        return $query->orWhere(function ($query) use ($currentUserId, $spaceId) {
            $query->where('status', 'pending')->where(function ($q) use ($currentUserId, $spaceId) {
                $q->where('user_id', $currentUserId);
                $q->orWhereHas('post.space', function ($spaceQuery) use ($currentUserId, $spaceId) {
                    if ($spaceId) {
                        $spaceQuery->where('id', $spaceId);
                    }
                    $spaceQuery->whereHas('members', function ($memberQuery) use ($currentUserId) {
                        $memberQuery->where('user_id', $currentUserId)
                            ->whereIn('role', ['admin', 'moderator']);
                    });
                });
            });
        });
    }
    /*
     * Find all the user ids of a child comment who commented on the parent comment including the parent comment author
     */
    public function getCommentParentUserIds($lastUserId = 0)
    {
        if (!$this->parent_id) {
            return [];
        }

        $parentComment = Comment::select(['user_id'])->find($this->parent_id);
        $allUserIds = Comment::where('parent_id', $this->parent_id)
            ->select(['user_id'])
            ->distinct('user_id')
            ->when($lastUserId, function ($query) use ($lastUserId) {
                $query->where('user_id', '>', $lastUserId);
            })
            ->get()
            ->pluck('user_id')
            ->toArray();

        if ($parentComment) {
            if ($parentComment->user_id > $lastUserId) {
                $allUserIds[] = $parentComment->user_id;
            }
        }

        return array_values(array_unique($allUserIds));
    }

    public function setMetaAttribute($value)
    {
        $this->attributes['meta'] = maybe_serialize($value);
    }

    public function getMetaAttribute($value)
    {
        $meta = maybe_unserialize($value);

        if (!$meta) {
            $meta = [];
        }

        return $meta;
    }

    public function getHumanExcerpt($length = 30)
    {
        $content = $this->title;
        if (!$content) {
            $content = $this->message;
        }

        return Helper::getHumanExcerpt($content, $length);
    }

    public function getEmailSubject($feed = null)
    {
        if (!$feed) {
            $feed = $this->post;
        }

        if ($this->parent_id) {
            if ($feed->title) {
                /* translators: %1$s is the feed title and %2$s is the comment author name */
                $emailSubject = \sprintf(__('New reply on comment at %1$s - %2$s', 'fluent-community'), $feed->title, $this->xprofile->display_name);
            } else {
                /* translators: %1$s is the post title and %2$s is the comment author name */
                $emailSubject = \sprintf(__('New reply of a comment in a post on %1$s - %2$s', 'fluent-community'), $this->post->getHumanExcerpt(40), $this->xprofile->display_name);
            }
        } else {
            if ($feed->title) {
                /* translators: %1$s is the feed title and %2$s is the comment author name */
                $emailSubject = \sprintf(__('New comment on %1$s - %2$s', 'fluent-community'), $feed->title, $this->xprofile->display_name);
            } else {
                /* translators: %1$s is the post title and %2$s is the comment author name */
                $emailSubject = \sprintf(__('New comment on a post on %1$s - %2$s', 'fluent-community'), $this->post->getHumanExcerpt(40), $this->xprofile->display_name);
            }
        }

        return $emailSubject;
    }
}
