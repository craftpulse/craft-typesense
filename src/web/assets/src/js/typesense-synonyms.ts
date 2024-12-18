import { createApp } from 'vue'
import TypesenseSynonyms from "@/vue/TypesenseSynonyms.vue";

const main = async () => {

    const app = createApp(TypesenseSynonyms)
    const root = app.mount('#typesense-synonyms')

    return root

};

main().then( (root) => {} )
