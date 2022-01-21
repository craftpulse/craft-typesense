<script lang="ts">

import { defineComponent } from 'vue'

export default defineComponent({

    props: {
        section: {
            type: Object,
            required: true,
        }
    }

})

</script>

<template>

    <div>
        {{ section }}
    </div>

    <!--<div class="text-base grid grid-cols-3 gap-4">



        {%- for section in sections -%}
        <div class="font-bold">
            {{- section.name|e -}}
        </div>
        <div class="font-bold">
            {{- section.type|title|e -}}
        </div>
        <div class="text-gray-600 text-sm">
            {{- url('settings/sections/' ~ section.id) -}}
        </div>
        {%- endfor -%}
    </div>-->

</template>
