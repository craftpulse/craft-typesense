<script lang="ts">

    import { defineComponent } from 'vue'
    import ListItemSection from '@/vue/molecules/listitems/ListItemSection.vue'

    export default defineComponent({

        components: {
            'list-item-section': ListItemSection
        },

        props: {
            sections: {
                type: Object,
                required: true,
            }
        },

    })

</script>

<template>

    <section class="mb-16 rounded-tr-sm rounded-tl-sm">
        <div class="grid grid-cols-5 rounded-tr-md rounded-tl-md bg-gray-100 mb-4">

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

        <div class="grid grid-cols-5">

            <list-item-section
                v-for="section in sections"
                :section="section"
                :key="section.id"
            />

        </div>
    </section>

    <div class="bg-sky-200 py-8 px-4">
        {{ sections }}
    </div>

</template>
