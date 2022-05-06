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

            console.log(this.api)

            await executeApi(this.api.client, this.api.action, variables, (response) => {
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

    <div class="px-6 py-2 border-b border-gray-100">
        <button
            type="button"
            class="cursor-pointer inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500"
            @click="syncCollection()"
        >
            Sync
        </button>
    </div>

</template>
