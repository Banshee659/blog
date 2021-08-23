<?php if (isset($component)) { $__componentOriginalc254754b9d5db91d5165876f9d051922ca0066f4 = $component; } ?>
<?php $component = $__env->getContainer()->make(Illuminate\View\AnonymousComponent::class, ['view' => 'components.layout','data' => []]); ?>
<?php $component->withName('layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php $component->withAttributes([]); ?>
    <?php $__currentLoopData = $posts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $post): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <article class="<?php echo e($loop->even ? 'fool' : 'mb-6'); ?>">
            <h1>
                <a href="/posts/<?php echo e($post->slug); ?>">
                    <?php echo e($post->title); ?>

                </a>
            </h1>
            <div><?php echo e($post->excerpt); ?>  </div>

        </article>

    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
 <?php if (isset($__componentOriginalc254754b9d5db91d5165876f9d051922ca0066f4)): ?>
<?php $component = $__componentOriginalc254754b9d5db91d5165876f9d051922ca0066f4; ?>
<?php unset($__componentOriginalc254754b9d5db91d5165876f9d051922ca0066f4); ?>
<?php endif; ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>


<?php /**PATH /Users/victordau/laravel-stuff/blog-laravel/resources/views/posts.blade.php ENDPATH**/ ?>