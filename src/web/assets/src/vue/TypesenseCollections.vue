<script lang="ts">

    // Async load the Vue 3 APIs we need from the Vue ESM
    import { defineComponent } from 'vue'
    import GridSections from '@/vue/organisms/grids/GridSections.vue'

    export default defineComponent({

        components: {
            'grid-sections': GridSections,
        },

    });

</script>
