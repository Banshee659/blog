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

    public $slug;

    public function __construct($title,$excerpt,$date,$body, $slug)
    {
        $this->title = $title;
        $this->body = $body;
        $this->date = $date;
        $this->excerpt = $excerpt;
        $this->slug = $slug;
    }

    public static function find($slug) {

            return static::all()->firstWhere( 'slug', $slug );
    }

    public static function findOrFail($slug) {

            $post = static::find($slug);

            if(!$post){
              throw new ModelNotFoundException();
            }

            return $post;
    }

    public static function all()
    {

      return cache()->rememberForever('posts.all', function ()
      {
        return collect(File::files(resource_path("posts/")))
          ->map(/**
           * @param $file
           * @return \Spatie\YamlFrontMatter\Document
           */ fn($file) => \Spatie\YamlFrontMatter\YamlFrontMatter::parseFile($file))
          ->map(/**
           * @param $document
           * @return Post
           */ fn($document) => new Post (
            $document->title,
            $document->excerpt,
            $document->date,
            $document->body(),
            $document->slug
          ))
          ->sortByDesc('date');
      });
    }
}
