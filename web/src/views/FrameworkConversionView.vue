<script setup lang="ts">
import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import FrameworkConversionWorkspace from '@/components/accounting/FrameworkConversionWorkspace.vue'
const store = useSupplierStore()
const supplier = computed(() => Number(store.currentSupplierId))
</script>
<template><FrameworkConversionWorkspace v-if="supplier" :key="supplier" :supplier="supplier" /></template>
