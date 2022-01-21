import { createApp } from 'vue'
import Typesense from '@/vue/TypesenseCollections.vue'

const main = async () => {

    const app = createApp(Typesense)
    const root = app.mount('#typesense-sections')

    return root

};

main().then( (root) => {
});
