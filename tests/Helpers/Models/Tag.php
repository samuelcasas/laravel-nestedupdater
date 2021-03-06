<?php
namespace Czim\NestedModelUpdater\Test\Helpers\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = [ 'name' ];

    public function posts()
    {
        return $this->morphTo(Post::class, 'taggable');
    }

    public function comments()
    {
        return $this->morphTo(Comment::class, 'taggable');
    }

    // for testing per-model rules class/method validation configuration
    public function rules()
    {
        return [
            'name' => 'in:custom,tag,rules',
        ];
    }
}
