<template>
  <aside class="sidebar" :class="{ collapsed: collapsed, 'mobile-open': mobileOpen }">
    <div class="sidebar-inner">
      <ul class="sidebar-list">
        <li v-for="cat in sidebarItems" :key="cat.id" class="sidebar-item">
          <a
            href="#"
            class="sidebar-link"
            :class="{ active: activeId === cat.id, 'has-children': cat.hasChildren }"
            @click.prevent="toggleCat(cat)"
          >
            <i :class="'io ' + cat.icon + ' icon-fw'"></i>
            <span class="sidebar-text">{{ cat.name }}</span>
            <svg
              v-if="cat.hasChildren"
              class="sidebar-arrow"
              :class="{ open: expandedId === cat.id }"
              width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            ><polyline points="9 18 15 12 9 6" /></svg>
          </a>
          <ul v-if="cat.hasChildren && expandedId === cat.id" class="sidebar-sub">
            <li v-for="child in cat.children" :key="child.id">
              <a
                href="#"
                class="sidebar-link sub-link"
                :class="{ active: activeId === child.id }"
                @click.prevent="scrollTo(child.parentId, child.id)"
              >
                <span class="sidebar-text">{{ child.name }}</span>
              </a>
            </li>
          </ul>
        </li>
      </ul>
      <div class="sidebar-bottom">
        <div v-if="adsStore.sidebarBottom.length" class="sidebar-ads">
          <AdBanner v-for="ad in adsStore.sidebarBottom" :key="ad.id" :ad="ad" />
        </div>
        <button class="sidebar-collapse-btn" @click="$emit('toggle-collapse')" aria-label="收起侧边栏">
          <svg :class="{ rotated: collapsed }" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6" />
          </svg>
          <span class="sidebar-text">{{ collapsed ? '展开' : '收起' }}</span>
        </button>
      </div>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useCategoryStore } from '../stores/categories';
import { useLayoutStore } from '../stores/layout';
import { useAdsStore } from '../stores/ads';
import AdBanner from './AdBanner.vue';

const props = defineProps({
  mobileOpen: { type: Boolean, default: false },
  collapsed: { type: Boolean, default: false },
});
const emit = defineEmits(['close-mobile', 'toggle-collapse']);

const store = useCategoryStore();
const layoutStore = useLayoutStore();
const adsStore = useAdsStore();
const activeId = ref(null);
const expandedId = ref(null);

const sidebarItems = computed(() => {
  const items = store.sidebarItems;
  if (!layoutStore.loaded || layoutStore.data.length === 0) return items;

  const layoutMap = {};
  layoutStore.data.forEach(item => {
    layoutMap[item.category_id] = item;
  });

  return items
    .filter(item => {
      const layout = layoutMap[item.id];
      return !layout || layout.visible !== false;
    })
    .map(item => {
      const layout = layoutMap[item.id];
      return { ...item, _sort: layout ? layout.sort_order : 999 };
    })
    .sort((a, b) => a._sort - b._sort);
});

function toggleCat(cat) {
  if (cat.hasChildren) {
    expandedId.value = expandedId.value === cat.id ? null : cat.id;
    const firstChild = cat.children?.[0];
    scrollTo(cat.id, firstChild?.id || cat.id);
    return;
  }
  scrollTo(cat.id);
}

function scrollTo(catId, activeCatId = catId) {
  activeId.value = activeCatId;
  window.dispatchEvent(new CustomEvent('navmate:select-category', {
    detail: { parentId: catId, categoryId: activeCatId },
  }));
  const el = document.getElementById('section-' + catId);
  if (el) {
    const headerHeight = document.querySelector('.main-header')?.offsetHeight || 0;
    const offset = headerHeight + 24;
    const top = el.getBoundingClientRect().top + window.scrollY - offset;
    window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
  }
  emit('close-mobile');
}

// Observe sections for active state
let observer = null;

function setupObserver() {
  if (observer) observer.disconnect();
  observer = new IntersectionObserver(
    (entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const id = entry.target.id.replace('section-', '');
          activeId.value = Number(id);
        }
      });
    },
    { rootMargin: '-60px 0px -60% 0px', threshold: 0 }
  );
  document.querySelectorAll('[id^="section-"]').forEach(el => observer.observe(el));
}

onMounted(() => {
  watch(() => store.categories.length, () => {
    setTimeout(setupObserver, 100);
  }, { immediate: true });
});

onUnmounted(() => {
  if (observer) observer.disconnect();
});
</script>
