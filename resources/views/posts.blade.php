<!doctype html>
<title>My Blog</title>
<link rel="stylesheet" href="/app.css">

<body>
<?php foreach ($posts as $post) : ?>
<article>
    <h1><?= $post->title; ?></h1>
    <div><?= $post->body; ?></div>

</article>

<?php endforeach;?>

<!-- <article>
<h1> <a href="/post">My first post</a></h1>
    <p>vksdg</p>
    <h1> <a href="/post">My 2nd post</a></h1>
    <p>mgsgskdgkfdng</p>
</article>

<article>
    <h1> <a href="/post">My 3rd post</a></h1>
    <p>9fs9f9dfusdu9f</p>
</article>-->

</body>

