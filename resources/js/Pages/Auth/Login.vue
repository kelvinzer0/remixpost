<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Login" />
    <div class="min-h-screen flex flex-col items-center justify-center bg-gray-100 px-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-brand-600">remixpost</h1>
                <p class="mt-2 text-sm text-gray-600">Self-hosted social media management</p>
            </div>
            <div class="bg-white p-8 rounded-lg shadow-md">
                <h2 class="text-xl font-semibold text-gray-900 mb-6">Sign in to your account</h2>
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <input v-model="form.email" type="email" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Password</label>
                        <input v-model="form.password" type="password" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                    </div>
                    <label class="flex items-center">
                        <input v-model="form.remember" type="checkbox"
                            class="rounded border-gray-300 text-brand-600 shadow-sm focus:ring-brand-500" />
                        <span class="ml-2 text-sm text-gray-600">Remember me</span>
                    </label>
                    <button type="submit" :disabled="form.processing"
                        class="w-full py-2 px-4 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50">
                        Sign in
                    </button>
                </form>
                <p class="mt-4 text-center text-sm text-gray-600">
                    Don't have an account?
                    <Link href="/register" class="font-medium text-brand-600 hover:text-brand-500">Register</Link>
                </p>
            </div>
        </div>
    </div>
</template>
