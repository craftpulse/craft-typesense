<script lang="ts">

import axios from 'axios'
import { executeApi } from '@/js/api/api'
import { defineComponent } from 'vue'

export default defineComponent({

    props: {
        section: {
            type: Object,
            required: true,
        },

        api: {
            type: Object,
            required: true,
        }
    },

    methods: {
        async syncCollection() {

            let variables = {
                ...this.section,
                sectionId: this.section.id,
                [this.api.csrf.name]: this.api.csrf.value,
            }

            await executeApi(this.api.client, 'typesense/sync-collection', variables, (response) => {
                console.table(response)
            })
        },
        async flushCollection() {

            let variables = {
                ...this.section,
                sectionId: this.section.id,
                [this.api.csrf.name]: this.api.csrf.value,
            }

            await executeApi(this.api.client, 'typesense/flush-collection', variables, (response) => {
                console.table(response)
            })
        }
    }

})

</script>

<template>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.index }}
    </div>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.name }}
    </div>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.type }}
    </div>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.entryCount }}
    </div>

    <!--div class="px-6 py-4 whitespace-nowrap flex items-center">
        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-emerald-100 text-emerald-800">
            Synced
        </span>
    </div-->

    <div class="px-6 py-2 border-b border-gray-100 space-x-2">
        <button
            type="button"
            class="cursor-pointer inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-700 hover:bg-indigo-900"
            @click="syncCollection()"
        >
            Sync
        </button>
        <button
            type="button"
            class="cursor-pointer inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-indigo-700 border border-solid border-indigo-700 hover:text-white hover:bg-indigo-900"
            @click="flushCollection()"
        >
            Flush
        </button>
    </div>

</template>
