<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use \App\Models\Post;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {

    $files = File::files(resource_path("posts/"));

    $posts = [];
    foreach($files as $file)
    {
        $document = \Spatie\YamlFrontMatter\YamlFrontMatter::parseFile($file);
        $posts[] = new Post (
            $document->title,
            $document->excerpt,
            $document->date,
            $document->body(),
            $document->slug
        );
    }

    //ddd($posts);

    return view ('posts', ['posts' => $posts]);
});

Route::get('posts/{post}', function ($slug) {

    return view('post', [
       'post' => Post::find($slug)
    ]);

})->where('post', '[A-z_\-]+');


