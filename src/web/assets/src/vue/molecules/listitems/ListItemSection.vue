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
                [this.api.csrf.name]: this.api.csrf.value,
            }

            await executeApi(this.api.client, this.api.action, variables, (response) => {
                console.table(response)
            })
        }
    }

})

</script>

<template>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.name }}
    </div>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.type }}
    </div>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        {{ section.entryCount }}
    </div>

    <div class="px-6 py-4 whitespace-nowrap flex items-center">
        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-emerald-100 text-emerald-800">
            Synced
        </span>
    </div>

    <div class="p-1 border-b border-gray-100">
        <button
            type="button"
            class="cursor-pointer inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500"
            @click="syncCollection()"
        >
            <svg class="-ml-1 mr-3 h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" fill="currentColor" aria-hidden="true">
                <path d="M432 256C432 269.3 421.3 280 408 280h-160v160c0 13.25-10.75 24.01-24 24.01S200 453.3 200 440v-160h-160c-13.25 0-24-10.74-24-23.99C16 242.8 26.75 232 40 232h160v-160c0-13.25 10.75-23.99 24-23.99S248 58.75 248 72v160h160C421.3 232 432 242.8 432 256z"/>
            </svg>
            Create Collection
        </button>
    </div>

</template>
