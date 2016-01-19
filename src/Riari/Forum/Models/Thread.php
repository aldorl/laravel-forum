<?php namespace Riari\Forum\Models;

use DB;
// use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Riari\Forum\Libraries\AccessControl;
use Riari\Forum\Libraries\Alerts;
use Riari\Forum\Libraries\Utils;
use Riari\Forum\Models\Traits\HasCustomAuthor;
use App\Models\Content;

// class Thread extends BaseModel {
class Thread extends Content {

    // use SoftDeletes, HasAuthor;
    use HasCustomAuthor;

    // Eloquent properties
    // protected $table         = 'forum_threads';
    protected static $singleTableType = 'thread';
    public    $timestamps    = true;
    // protected $dates         = ['created_at', 'updated_at', 'deleted_at'];
    protected $dates         = ['created_at', 'updated_at'];
    protected $appends       = ['lastPage', 'lastPost', 'lastPostRoute', 'route', 'lockRoute', 'pinRoute', 'replyRoute', 'deleteRoute'];
    protected $guarded       = ['id'];
    protected $with          = ['readers'];

    // Thread constants
    const     STATUS_UNREAD  = 'unread';
    const     STATUS_UPDATED = 'updated';

    // Single Inheritance Table contstants
    const     VIEW_COUNT     = 0;

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    // public function category()
    // {
    //     return $this->belongsTo('\Riari\Forum\Models\Category', 'parent_category');
    // }

    public function readers()
    {
        return $this->belongsToMany(config('forum.integration.user_model'), 'forum_threads_read', 'thread_id', 'user_id')->withTimestamps();
    }

    public function posts()
    {
        return $this->hasMany('\Riari\Forum\Models\Post', 'parent_thread');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeRecent($query)
    {
        $cutoff = config('forum.preferences.thread.cutoff_age');
        return $query->where('updated_at', '>', date('Y-m-d H:i:s', strtotime($cutoff)));
    }

    /*
    |--------------------------------------------------------------------------
    | Attributes
    |--------------------------------------------------------------------------
    */

    // Route attributes

    public function getRouteAttribute()
    {
        return $this->getRoute('forum.get.view.thread');
    }

    public function getReplyRouteAttribute()
    {
        return $this->getRoute('forum.get.reply.thread');
    }

    public function getPinRouteAttribute()
    {
        return $this->getRoute('forum.post.pin.thread');
    }

    public function getLockRouteAttribute()
    {
        return $this->getRoute('forum.post.lock.thread');
    }

    public function getDeleteRouteAttribute()
    {
        return $this->getRoute('forum.delete.thread');
    }

    public function getLastPostRouteAttribute()
    {
        return "{$this->route}?page={$this->lastPage}#post-{$this->lastPost->id}";
    }

    // General attributes

    public function getPostsPaginatedAttribute()
    {
        return $this->posts()->paginate(config('forum.preferences.posts_per_thread'));
    }

    public function getPageLinksAttribute()
    {
        return $this->postsPaginated->render();
    }

    public function getLastPageAttribute()
    {
        return $this->postsPaginated->lastPage();
    }

    public function getLastPostAttribute()
    {
        return $this->posts()->orderBy('created_at', 'desc')->first();
    }

    public function getLastPostTimeAttribute()
    {
        return $this->lastPost->created_at;
    }

    public function getReplyCountAttribute()
    {
        return ($this->posts->count() - 1);
    }

    public function getOldAttribute()
    {
        $cutoff = config('forum.preferences.thread.cutoff_age');
        return (!$cutoff || $this->updated_at->timestamp < strtotime($cutoff));
    }

    public function getViewCountAttribute()
    {
        // return $this->attributes['view_count'];
        return json_decode($this->data, true)[self::VIEW_COUNT];
    }

    // Current user: reader attributes

    public function getReaderAttribute()
    {
        if (!is_null(Utils::getCurrentUser()))
        {
            $reader = $this->readers()->where('user_id', '=', Utils::getCurrentUser()->id)->first();

            return (!is_null($reader)) ? $reader->pivot : null;
        }

        return null;
    }

    public function getUserReadStatusAttribute()
    {
        if (!$this->old && !is_null(Utils::getCurrentUser()))
        {
            if (is_null($this->reader))
            {
                return self::STATUS_UNREAD;
            }

            return ($this->updatedSince($this->reader)) ? self::STATUS_UPDATED : false;
        }

        return false;
    }

    // Current user: permission attributes

    public function getUserCanReplyAttribute()
    {
        return AccessControl::check($this, 'reply_to_thread', false);
    }

    public function getCanReplyAttribute()
    {
        return $this->userCanReply;
    }

    public function getUserCanPinAttribute()
    {
        return AccessControl::check($this, 'pin_threads', false);
    }

    public function getCanPinAttribute()
    {
        return $this->userCanPin;
    }

    public function getUserCanLockAttribute()
    {
        return AccessControl::check($this, 'lock_threads', false);
    }

    public function getCanLockAttribute()
    {
        return $this->userCanLock;
    }

    public function getUserCanDeleteAttribute()
    {
        return AccessControl::check($this, 'delete_threads', false);
    }

    public function getCanDeleteAttribute()
    {
        return $this->userCanDelete;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getRouteComponents()
    {
        $components = array(
            // 'categoryID'    => $this->category->id,
            // 'categoryAlias' => Str::slug($this->category->title, '-'),
            'categoryID'    => '9',
            'categoryAlias' => 'global',
            'threadID'      => $this->id,
            // 'threadAlias'   => Str::slug($this->title, '-')
            'threadAlias'   => Str::slug($this->name, '-')
        );

        return $components;
    }

    public function markAsRead($userID)
    {
        if (!$this->old)
        {
            if (is_null($this->reader))
            {
                $this->readers()->attach($userID);
            }
            elseif ($this->updatedSince($this->reader))
            {
                $this->reader->touch();
            }
        }
    }

    public function toggle($property)
    {
        parent::toggle($property);

        Alerts::add('success', trans('forum::base.thread_updated'));
    }





    /*
    |--------------------------------------------------------------------------
    | Attributes
    |--------------------------------------------------------------------------
    */

    public function getPostedAttribute()
    {
        return $this->getTimeAgo($this->created_at);
    }

    public function getUpdatedAttribute()
    {
        return $this->getTimeAgo($this->updated_at);
    }

    protected function rememberAttribute($item, $function)
    {
        $cacheItem = get_class($this).$this->id.$item;

        $value = Cache::remember($cacheItem, config('forum.preferences.cache_lifetime'), $function);

        return $value;
    }

    protected static function clearAttributeCache($model)
    {
        foreach ($model->appends as $attribute) {
            $cacheItem = get_class($model).$model->id.$attribute;
            Cache::forget($cacheItem);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    // Returns true if this model has been updated since the given model
    public function updatedSince(&$model)
    {
        return ($this->updated_at > $model->updated_at);
    }

    // Returns route components for building routes
    // protected function getRouteComponents()
    // {
    //     $components = array();
    //     return $components;
    // }

    // Returns a route using the current set route components
    protected function getRoute($name, $components = array())
    {
        return route($name, array_merge($this->getRouteComponents(), $components));
    }

    // Returns a human readable diff of the given timestamp
    protected function getTimeAgo($timestamp)
    {
        return Carbon::createFromTimeStamp(strtotime($timestamp))->diffForHumans();
    }

    // Toggles a property (column) on the model and saves it
    // public function toggle($property)
    // {
    //     $this->$property = !$this->$property;
    //     $this->save();
    // }

}
