<template>
  <a
    :href="site.url"
    target="_blank"
    rel="noopener noreferrer"
    class="site-card"
    :aria-label="'访问 ' + site.title"
    :title="site.title"
    @click="trackClick"
  >
    <button
      v-if="authStore.isAuthenticated"
      class="site-fav"
      :class="{ active: isFav }"
      @click.stop.prevent="handleFav"
      :aria-label="isFav ? '取消收藏 ' + site.title : '收藏 ' + site.title"
      :title="isFav ? '取消收藏' : '收藏'"
    >
      <svg width="14" height="14" viewBox="0 0 24 24" :fill="isFav ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
    </button>
    <button
      class="site-copy"
      :class="{ copied }"
      @click.stop.prevent="copyLink"
      :aria-label="copied ? '已复制链接' : '复制 ' + site.title + ' 链接'"
      :title="copied ? '已复制' : '复制链接'"
    >
      <svg v-if="!copied" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
    </button>
    <div class="site-favicon">
      <img v-if="site.favicon_url && !imgError" :src="site.favicon_url" :alt="site.title" loading="lazy" @error="imgError = true" />
      <span v-else class="site-favicon-letter">{{ firstLetter }}</span>
    </div>
    <div class="site-info">
      <span class="site-name">{{ site.title }}</span>
      <span class="site-desc" v-if="site.description">{{ site.description }}</span>
    </div>
    <span class="site-goto">&#8599;</span>
  </a>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useFavoritesStore } from '../stores/favorites';

const props = defineProps({
  site: { type: Object, required: true },
});
const imgError = ref(false);
const copied = ref(false);
let copyTimer = null;
const authStore = useAuthStore();
const favoritesStore = useFavoritesStore();

const firstLetter = computed(() => {
  return props.site.title ? props.site.title.charAt(0).toUpperCase() : '?';
});

// Cache isFavorite as a single computed per card (was called 3x in template)
const isFav = computed(() => favoritesStore.idSet.has(props.site.id));

function handleFav() {
  favoritesStore.toggleFavorite(props.site);
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(props.site.url);
  } catch (e) {
    const textarea = document.createElement('textarea');
    textarea.value = props.site.url;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
  }

  copied.value = true;
  clearTimeout(copyTimer);
  copyTimer = setTimeout(() => {
    copied.value = false;
  }, 1200);
}

onUnmounted(() => {
  clearTimeout(copyTimer);
});

// Cache CSRF token once at module level
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function trackClick() {
  if (!props.site.id) return;
  // _token is required for sendBeacon, which cannot set request headers
  const payload = JSON.stringify({ site_id: props.site.id, _token: csrfToken });
  const url = '/api/click';
  if (navigator.sendBeacon) {
    navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
  } else {
    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'Accept': 'application/json',
      },
      body: payload,
    }).catch(() => {});
  }
}
</script>
