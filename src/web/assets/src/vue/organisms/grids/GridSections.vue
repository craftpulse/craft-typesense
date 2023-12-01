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

    <section class="rounded-tr-sm rounded-tl-sm">
        <div class="grid grid-cols-5 rounded-tr-md rounded-tl-md bg-gray-100">

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Index
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Name
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Type
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Results
            </div>

            <!--div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Status
            </div-->

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Sync
            </div>

        </div>

        <div class="grid grid-cols-5">

            <list-item-section
                v-for="section in sections"
                :section="section"
                :key="section.id"
                :api="api"
            />

        </div>
    </section>
</template>
