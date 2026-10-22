<template>
  <div class="search-banner">
    <div class="banner-orb banner-orb-1"></div>
    <div class="banner-orb banner-orb-2"></div>
    <div class="banner-orb banner-orb-3"></div>
    <div class="search-container">
      <!-- Inner search box (centered, constrained width) -->
      <div class="big-search">
        <!-- Category tabs -->
        <div class="search-group-tabs">
          <button
            v-for="g in groups" :key="g.id"
            class="search-group-tab"
            :class="{ active: activeGroup === g.id }"
            @click="setGroup(g.id)"
          >{{ g.label }}</button>
        </div>

        <!-- Search input -->
        <form class="search-form" @submit.prevent="doSearch">
          <input
            id="site-search-input"
            ref="searchInput"
            v-model="keyword"
            name="q"
            type="text"
            class="search-input"
            :class="{ 'has-suggestions': showSuggestions && suggestions.length }"
            :placeholder="placeholder"
            aria-label="搜索关键词"
            autocomplete="off"
            role="combobox"
            :aria-expanded="showSuggestions && suggestions.length > 0"
            aria-controls="site-search-suggestions"
            @input="onInput"
            @focus="showSuggestions = suggestions.length > 0"
            @keydown.down.prevent="moveSuggestion(1)"
            @keydown.up.prevent="moveSuggestion(-1)"
            @keydown.enter="handleEnter"
            @keydown.esc="closeSuggestions"
          />
          <span class="search-shortcut" aria-hidden="true">/ 或 ⌘K</span>
          <button type="submit" class="search-btn" aria-label="搜索">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8" />
              <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
          </button>
        </form>

        <!-- Suggestions dropdown -->
        <Transition name="dropdown">
          <div
            v-if="showSuggestions && suggestions.length > 0"
            id="site-search-suggestions"
            class="search-suggestions"
            role="listbox"
          >
            <div
              v-for="(item, idx) in suggestions" :key="item.id"
              class="search-suggestion-item"
              :class="{ active: activeSuggestionIdx === idx }"
              role="option"
              :aria-selected="activeSuggestionIdx === idx"
              @mouseenter="activeSuggestionIdx = idx"
              @mousedown.prevent="selectSuggestion(item)"
            >
              <img
                v-if="item.favicon_url && !item.favicon_failed"
                :src="item.favicon_url"
                class="search-suggestion-icon"
                alt=""
                @error="markFaviconFailed(item)"
              />
              <span v-else class="search-suggestion-letter">{{ item.title.charAt(0) }}</span>
              <span class="search-suggestion-body">
                <span class="search-suggestion-text">{{ item.title }}</span>
                <span class="search-suggestion-meta">
                  <span v-if="item.category_path">{{ item.category_path }}</span>
                  <span v-if="item.domain">{{ item.domain }}</span>
                </span>
              </span>
            </div>
          </div>
        </Transition>

        <!-- Engine tabs -->
        <div class="search-engine-tabs">
          <button
            v-for="e in currentEngines" :key="e.id"
            class="search-engine-tab"
            :class="{ active: activeEngine === e.id }"
            @click="setEngine(e.id)"
          >{{ e.label }}</button>
        </div>
      </div>

      <!-- Bulletin (inside search-container, outside big-search) -->
      <div class="bulletin-bar">
        <span class="bulletin-icon">&#128197;</span>
        <BulletinClock />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, inject, ref, onMounted, onUnmounted } from 'vue';
import { useSearchStore } from '../stores/search';
import { useCategoryStore } from '../stores/categories';
import BulletinClock from './BulletinClock.vue';

const searchStore = useSearchStore();
const categoryStore = useCategoryStore();
const siteName = inject('siteName', '导航');

// Suggestions
const suggestions = ref([]);
const showSuggestions = ref(false);
const activeSuggestionIdx = ref(-1);
const searchInput = ref(null);
let debounceTimer = null;

onMounted(() => {
  document.addEventListener('click', onClickOutside);
  document.addEventListener('keydown', onGlobalKeydown);
});

onUnmounted(() => {
  clearTimeout(debounceTimer);
  document.removeEventListener('click', onClickOutside);
  document.removeEventListener('keydown', onGlobalKeydown);
});

const keyword = computed({
  get: () => searchStore.keyword,
  set: (v) => { searchStore.keyword = v; },
});
const activeGroup = computed(() => searchStore.activeGroup);
const activeEngine = computed(() => searchStore.activeEngine);
const groups = computed(() => searchStore.groups);
const currentEngines = computed(() => searchStore.currentEngines);
const placeholder = computed(() => searchStore.placeholder);

function closeSuggestions() {
  showSuggestions.value = false;
  activeSuggestionIdx.value = -1;
}

function clearSuggestions() {
  closeSuggestions();
  suggestions.value = [];
}

function setGroup(id) {
  searchStore.setGroup(id);
  clearSuggestions();
}
function setEngine(id) {
  searchStore.setEngine(id);
  clearSuggestions();
}
function doSearch() {
  clearSuggestions();
  searchStore.doSearch();
}

function onInput() {
  const val = searchStore.keyword.trim();
  const engine = searchStore.currentEngine;

  if (engine?.type !== 'site' || !val) {
    clearSuggestions();
    return;
  }

  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    const q = val.toLowerCase();
    suggestions.value = categoryStore.flatSites
      .map(site => ({ ...site, match_score: getMatchScore(site, q) }))
      .filter(site => site.match_score > 0)
      .sort((a, b) => b.match_score - a.match_score || String(a.title).localeCompare(String(b.title), 'zh-Hans-CN'))
      .slice(0, 8);
    showSuggestions.value = suggestions.value.length > 0;
    activeSuggestionIdx.value = suggestions.value.length > 0 ? 0 : -1;
  }, 180);
}

function getMatchScore(site, q) {
  const title = String(site.title || '').toLowerCase();
  const domain = String(site.domain || '').toLowerCase();
  const path = String(site.category_path || '').toLowerCase();
  const url = String(site.url || '').toLowerCase();

  if (title === q) return 100;
  if (title.startsWith(q)) return 90;
  if (domain === q || domain.startsWith(q)) return 82;
  if (title.includes(q)) return 72;
  if (domain.includes(q)) return 62;
  if (path.includes(q)) return 44;
  if (url.includes(q)) return 30;

  return 0;
}

function markFaviconFailed(item) {
  item.favicon_failed = true;
}

function selectSuggestion(item) {
  searchStore.keyword = item.title;
  clearSuggestions();
  searchStore.doSearch();
}

function moveSuggestion(step) {
  if (!showSuggestions.value || suggestions.value.length === 0) return;

  const total = suggestions.value.length;
  activeSuggestionIdx.value = (activeSuggestionIdx.value + step + total) % total;
}

function handleEnter(event) {
  if (!showSuggestions.value || activeSuggestionIdx.value < 0) return;

  event.preventDefault();
  selectSuggestion(suggestions.value[activeSuggestionIdx.value]);
}

function onClickOutside(e) {
  if (!e.target.closest('.big-search')) {
    closeSuggestions();
  }
}

function onGlobalKeydown(event) {
  const target = event.target;
  const isTyping = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target?.isContentEditable;
  const isFocusShortcut = event.key === '/' || ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k');

  if (!isFocusShortcut || isTyping) return;

  event.preventDefault();
  searchInput.value?.focus();
}
</script>
