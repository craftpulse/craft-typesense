<script lang="ts">

    import { defineComponent } from 'vue'
    import { configureApi, executeApi } from '@/js/api/api'
    import ListItemSection from '@/vue/molecules/listitems/ListItemSection.vue'

    export default defineComponent({

        components: {
            'list-item-section': ListItemSection
        },

        props: {
            sections: {
                type: Object,
                required: true,
            },

            apiConfig: {
                type: Object,
                required: true,
            }
        },

        data: () => ({
            api: null,
        }),

        methods: {

            createApi(): Object {
                const api = {
                    client: axios.create(configureApi(this.apiConfig.baseUrl)),
                    csrf: this.apiConfig.csrf,
                    action: this.apiConfig.action,
                }

                this.api = api;
            }

        },

        async created() {
            await this.createApi()
        }

    })

</script>

<template>

    <section class="mb-16 rounded-tr-sm rounded-tl-sm">
        <div class="grid grid-cols-6 rounded-tr-md rounded-tl-md bg-gray-100 mb-4">

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Index
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Section
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Type
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Total Entries
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Status
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Sync
            </div>

        </div>

        <div class="grid grid-cols-6">

            <list-item-section
                v-for="section in sections"
                :section="section"
                :key="section.id"
                :api="api"
            />

        </div>
    </section>

    <div class="bg-emerald-200 py-8 px-4 max-w-xl">

    </div>

    <div class="bg-sky-200 py-8 px-4 max-w-xl">
        {{ sections }}
    </div>

    <div class="bg-rose-200 my-8 py-8 px-4 max-w-xl">
        {{ apiConfig }}
    </div>

</template>
