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

    data: () => ({
        type: null,
    }),

    methods: {
        async syncCollection() {

            this.type = 'sync'

            let variables = {
                ...this.section,
                sectionId: this.section.id,
                [this.api.csrf.name]: this.api.csrf.value,
            }

            await executeApi(this.api.client, 'typesense/sync-collection', variables, (response) => {
                this.type = null
                // console.table(response)
            })
        },
        async flushCollection() {

            this.type = 'flush'

            let variables = {
                ...this.section,
                sectionId: this.section.id,
                [this.api.csrf.name]: this.api.csrf.value,
            }

            await executeApi(this.api.client, 'typesense/flush-collection', variables, (response) => {
                this.type = null
                // console.table(response)
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
            <span>Sync</span>
            <svg v-if="type === 'sync'" class="animate-spin ml-1 h-3 w-3 text-white mb-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        </button>
        <button
            type="button"
            class="cursor-pointer inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-indigo-700 border border-solid border-indigo-700 hover:text-white hover:bg-indigo-900"
            @click="flushCollection()"
        >
            <span>Flush</span>
            <svg v-if="type === 'flush'" class="animate-spin ml-1 h-3 w-3 text-white mb-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        </button>
    </div>

</template>
