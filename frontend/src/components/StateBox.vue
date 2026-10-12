<script setup>defineProps({loading:Boolean,error:String,empty:Boolean,emptyText:{type:String,default:'No hay resultados disponibles.'}});</script>
<template><div v-if="loading" class="state">⏳ &nbsp; Cargando datos…</div><div v-else-if="error" class="state error">{{error}}</div><div v-else-if="empty" class="state">{{emptyText}}</div></template>
