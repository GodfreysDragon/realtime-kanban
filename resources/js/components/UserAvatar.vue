<script setup>
import { computed } from 'vue';

const props = defineProps({
    user: { type: Object, required: true },
    size: { type: String, default: 'md' },
});

const initials = computed(() =>
    props.user.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join(''),
);

const sizeClass = computed(() => ({ xs: 'size-5 text-[10px]', sm: 'size-7 text-xs', md: 'size-8 text-xs' })[props.size]);
</script>

<template>
    <span
        class="inline-grid shrink-0 place-items-center rounded-full font-semibold text-white ring-2 ring-white select-none dark:ring-slate-900"
        :class="sizeClass"
        :style="{ backgroundColor: user.color }"
        :title="user.name"
    >
        {{ initials }}
    </span>
</template>
