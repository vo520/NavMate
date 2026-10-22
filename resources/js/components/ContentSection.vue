<template>
  <div :id="'section-' + category.id" class="content-section">
    <div class="section-header">
      <h4 class="section-title">
        <i v-if="category.icon" :class="'io ' + category.icon + ' icon-fw'" style="margin-right: 6px"></i>
        {{ cleanName }}
      </h4>
      <ul v-if="tabs.length > 1" ref="tabsList" class="section-tabs">
        <li
          v-for="(tab, idx) in tabs" :key="tab.id"
          class="section-tab"
          :class="{ active: activeTabIdx === idx }"
          role="button"
          tabindex="0"
          :aria-pressed="activeTabIdx === idx"
          @click="selectTab(idx)"
          @keydown.enter.prevent="selectTab(idx)"
          @keydown.space.prevent="selectTab(idx)"
        >
          <span>{{ tab.name }}</span>
          <em v-if="tab.sites?.length" class="section-tab-count">{{ tab.sites.length }}</em>
        </li>
      </ul>
    </div>
    <div class="site-grid">
      <SiteCard
        v-for="site in activeSites" :key="site.id"
        :site="site"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import SiteCard from './SiteCard.vue';

const props = defineProps({
  category: { type: Object, required: true },
});

const activeTabIdx = ref(0);
const tabsList = ref(null);

const cleanName = computed(() => props.category.name.replace(/[🔥💻🎮📌]/g, ''));

const tabs = computed(() => {
  if (props.category.children && props.category.children.length > 0) {
    return props.category.children;
  }
  return props.category.sites && props.category.sites.length > 0
    ? [{ id: props.category.id, name: props.category.name, sites: props.category.sites }]
    : [];
});

const activeSites = computed(() => {
  const tab = tabs.value[activeTabIdx.value];
  return tab ? (tab.sites || []) : [];
});

function selectTab(idx) {
  activeTabIdx.value = idx;
  alignActiveTab();
}

function alignActiveTab() {
  nextTick(() => {
    const activeTab = tabsList.value?.children?.[activeTabIdx.value];
    activeTab?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
  });
}

function handleCategorySelect(event) {
  if (Number(event.detail?.parentId) !== Number(props.category.id)) return;

  const nextIdx = tabs.value.findIndex(tab => Number(tab.id) === Number(event.detail.categoryId));
  if (nextIdx !== -1) {
    activeTabIdx.value = nextIdx;
    alignActiveTab();
  }
}

onMounted(() => {
  window.addEventListener('navmate:select-category', handleCategorySelect);
});

onUnmounted(() => {
  window.removeEventListener('navmate:select-category', handleCategorySelect);
});
</script>
