<script lang="ts">

import { defineComponent } from 'vue'
import ListItemSynonymSection from "@/vue/molecules/listitems/ListItemSynonymSection.vue";

export default defineComponent({

    components: {
        'list-item-section': ListItemSynonymSection
    },

    props: {
        sections: {
            type: Object,
            required: true,
        },
    },

})

</script>

<template>

    <section class="rounded-tr-sm rounded-tl-sm">
        <div class="grid grid-cols-3 rounded-tr-md rounded-tl-md bg-gray-100">

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Index
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Name
            </div>

            <div class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Action(s)
            </div>

        </div>

        <div class="grid grid-cols-3">

            <list-item-section
                v-for="section in sections"
                :section="section"
                :key="section.id"
            />

        </div>
    </section>
</template>
