<x-layout>
    @foreach ($posts as $post)
        <article class="{{$loop->even ? 'fool' : 'mb-6'}}">
            <h1>
                <a href="/posts/{{$post->slug}}">
                    {{$post->title}}
                </a>
            </h1>
            <div>{{$post->excerpt}}  </div>

        </article>

    @endforeach
</x-layout>


