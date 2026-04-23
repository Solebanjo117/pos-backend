---
name: vue-tailwind-development
description: >-
  Builds and styles Vue 3 components using Composition API, Inertia.js, and Tailwind CSS v4.
  Activates when creating Vue components, pages, modals, forms, or handling frontend logic;
  or when the user mentions Vue, Inertia, component, props, emits, script setup, or frontend structure.
---

# Vue + Tailwind Development

## When to Apply

Activate this skill when:

- Creating or modifying Vue 3 components (`.vue` files)
- Working with Inertia.js pages or layouts
- Implementing forms, modals, or interactive UI elements
- Handling component state, props, emits, or lifecycle hooks
- Integrating Tailwind CSS classes within Vue templates

## Core Principles

### Vue 3 Composition API

- Always use `<script setup lang="ts">` syntax.
- Use `ref` for reactive state and `computed` for derived values.
- Define props with `defineProps<Type>()` and emits with `defineEmits<Type>()`.
- Prefer TypeScript interfaces for props and emits to ensure type safety.

### Inertia.js Integration

- Use `router.visit()`, `router.post()`, etc., for navigation and form submissions.
- Access shared data via `usePage().props`.
- Handle form state with `useForm()` from `@inertiajs/vue3`.
- For file uploads, use `FormData` and set `forceFormData: true` in Inertia options.

### Tailwind CSS v4 in Vue

- Use Tailwind utility classes directly in templates.
- Follow project conventions for dark mode (`dark:` variant).
- Use `gap` for spacing between sibling elements.
- Extract complex class strings into computed properties or constants if they become unreadable.

## Component Structure

<code-snippet name="Standard Component Structure" lang="vue">
<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

interface Props {
    title: string
    isActive?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    isActive: false
})

const emit = defineEmits<{
    (e: 'update', value: string): void
    (e: 'close'): void
}>()

const count = ref(0)
const doubled = computed(() => count.value * 2)

const handleSubmit = () => {
    // Logic here
    emit('update', 'new value')
}
</script>

<template>
    <div class="p-6 bg-white dark:bg-gray-900 rounded-lg shadow">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ title }}</h2>
        <p class="mt-2 text-gray-600 dark:text-gray-400">
            Count: {{ count }} (Doubled: {{ doubled }})
        </p>
        <button
            @click="handleSubmit"
            class="mt-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors"
        >
            Update
        </button>
    </div>
</template>
</code-snippet>

## Forms and Validation

- Use `useForm` for handling form state and submission.
- Display validation errors using `form.errors.fieldName`.
- Reset form state on success or modal close.

<code-snippet name="Inertia Form Pattern" lang="vue">
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

const form = useForm({
    name: '',
    email: '',
})

const submit = () => {
    form.post('/users', {
        onSuccess: () => {
            form.reset()
            // Handle success
        },
    })
}
</script>

<template>
    <form @submit.prevent="submit">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
            <input
                v-model="form.name"
                type="text"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-700"
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
        </div>
        <button
            type="submit"
            :disabled="form.processing"
            class="mt-4 px-4 py-2 bg-blue-600 text-white rounded disabled:opacity-50"
        >
            {{ form.processing ? 'Saving...' : 'Save' }}
        </button>
    </form>
</template>
</code-snippet>

## Modal and Dialog Patterns

- Use Teleport for modals to ensure they render outside the main app container.
- Handle ESC key and backdrop click to close.
- Focus trap for accessibility (if required).

<code-snippet name="Simple Modal Pattern" lang="vue">
<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'

const emit = defineEmits<{ (e: 'close'): void }>()

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', handleKeydown))
onUnmounted(() => window.removeEventListener('keydown', handleKeydown))
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div
                class="bg-white dark:bg-gray-900 rounded-lg shadow-xl max-w-lg w-full mx-4 p-6"
                @click.stop
            >
                <slot />
            </div>
        </div>
    </Teleport>
</template>
</code-snippet>

## Common Pitfalls

- Using Options API instead of Composition API (`<script setup>`).
- Forgetting to handle dark mode variants in new components.
- Mutating props directly (use `emit` or local state).
- Not cleaning up event listeners or intervals in `onUnmounted`.
- Using `v-if` and `v-for` on the same element (use a wrapper or computed property).
- Hardcoding colors instead of using Tailwind theme variables or existing project colors.

## References

- See `tailwindcss-development` skill for pure CSS/Tailwind patterns.
- Check `resources/js/components/` for existing component conventions.
- Refer to `AGENTS.md` for frontend/backend communication patterns.
