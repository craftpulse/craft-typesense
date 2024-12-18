<script lang="ts">

import { defineComponent } from 'vue'

export default defineComponent({

    props: {
        section: {
            type: Object,
            required: true,
        },
    },

    methods: {
        getUrl() {
            return window.location.href
        }
    },

})

</script>

<template>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.index }}
    </div>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.name }}
    </div>

    <div class="px-6 py-2 space-x-2">
        <a
            :href="`${getUrl()}/${section.index}`"
            :title="`Edit the synonyms for ${section.name}`"
            class="cursor-pointer inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-700 hover:bg-indigo-900"
        >
            <span>Edit</span>
        </a>
    </div>

</template>
