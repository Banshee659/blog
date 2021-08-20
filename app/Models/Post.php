<?php


namespace App\Models;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\File;

class Post
{
    public $title;

    public $excerpt;

    public $date;

    public $body;

    public function __construct($title,$excerpt,$date,$body)
    {
        $this->title = $title;
        $this->body = $body;
        $this->date = $date;
        $this->excerpt = $excerpt;
    }

    public static function find($slug) {

            if(!file_exists($path = resource_path("/posts/{$slug}.html")))
            {
                throw new ModelNotFoundException();
            }


        return cache()->remember("posts.{$slug}", 1200,  fn() => file_get_contents($path));
    }

    public static function all() {

        return File::files(resource_path("posts/"));
    }

}
