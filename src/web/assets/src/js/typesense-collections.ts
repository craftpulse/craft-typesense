import { createApp } from 'vue'
import TypesenseCollections from '@/vue/TypesenseCollections.vue'

const main = async () => {

    const app = createApp(TypesenseCollections)
    const root = app.mount('#typesense-collections')

    return root

};

main().then( (root) => {} )
