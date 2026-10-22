<template>
  <div>
    <PageToolbar>
      <template #left>
        <div class="toolbar-title"><ImportOutlined /> 书签导入</div>
        <span class="toolbar-hint">支持 Chrome / Firefox / Edge 导出的 HTML 书签文件</span>
      </template>
      <template #right>
        <a-button v-if="previewData || importResult" :disabled="previewing || importing" @click="resetImport">重新选择</a-button>
      </template>
    </PageToolbar>

    <div class="admin-card bookmark-card">
      <div class="bookmark-body">
        <!-- 上传区 -->
        <div v-if="!previewData && !importResult" class="upload-section">
          <a-upload-dragger
            name="bookmark_file"
            accept=".html,.htm"
            :max-count="1"
            :before-upload="beforeUpload"
            :disabled="previewing || importing"
            :show-upload-list="false"
          >
            <p class="ant-upload-drag-icon">
              <InboxOutlined style="font-size: 48px; color: var(--admin-primary)" />
            </p>
            <p class="upload-text">{{ uploadStatusText }}</p>
            <p v-if="selectedFileName" class="upload-file" :title="selectedFileName">
              <span>{{ selectedFileName }}</span>
              <span v-if="selectedFileSize"> · {{ selectedFileSize }}</span>
            </p>
            <p class="upload-hint">支持 Chrome / Firefox / Edge 导出的 HTML 书签文件，单文件不超过 10MB</p>
            <p class="upload-status" role="status" aria-live="polite">{{ uploadAssistText }}</p>
          </a-upload-dragger>
        </div>

        <!-- 预览 -->
        <div v-if="previewData && !importResult" class="preview-section">
          <a-divider>预览结果</a-divider>
          <a-row :gutter="16">
            <a-col :xs="24" :sm="12">
              <div class="preview-stat">
                <div class="preview-stat-value">{{ previewData.total_bookmarks || 0 }}</div>
                <div class="preview-stat-label">书签数</div>
              </div>
            </a-col>
            <a-col :xs="24" :sm="12">
              <div class="preview-stat">
                <div class="preview-stat-value">{{ previewData.total_folders || 0 }}</div>
                <div class="preview-stat-label">文件夹</div>
              </div>
            </a-col>
          </a-row>
          <a-alert
            class="preview-alert"
            type="info"
            show-icon
            message="将按实用场景自动分类"
            description="系统会把浏览器多级文件夹扁平整理为前台可直接点击的二级分类，避免导入后看不到深层书签。"
          />
          <a-checkbox v-model:checked="skipDuplicates" class="skip-checkbox">跳过重复书签</a-checkbox>
          <div class="folder-preview">
            <div v-for="folder in previewData.folders" :key="folder.name" class="folder-item">
              <div class="folder-name">{{ folder.name }} ({{ folder.count || 0 }})</div>
              <div class="folder-tags">
                <a-tag v-for="item in (folder.children || []).slice(0, 5)" :key="item.title">{{ item.title }}</a-tag>
                <span v-if="(folder.children || []).length > 5" class="more-hint">...等 {{ (folder.children || []).length - 5 }} 个</span>
              </div>
            </div>
          </div>
          <div class="preview-actions">
            <a-button :disabled="importing" @click="resetPreview">重新选择</a-button>
            <a-button type="primary" :loading="importing" :disabled="!canImport" @click="handleImport">开始导入</a-button>
          </div>
        </div>

        <!-- 结果 -->
        <a-result v-if="importResult" status="success" title="导入完成" :sub-title="importResult.message">
          <template #extra>
            <div v-if="importStats.length" class="result-stats">
              <div v-for="item in importStats" :key="item.label" class="result-stat">
                <strong>{{ item.value }}</strong>
                <span>{{ item.label }}</span>
              </div>
            </div>
            <a-button type="primary" @click="resetImport">继续导入</a-button>
          </template>
        </a-result>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, shallowRef } from 'vue';
import { message } from 'antdv-next';
import request from '../utils/request';
import PageToolbar from '../components/PageToolbar.vue';
import { InboxOutlined, ImportOutlined } from '@ant-design/icons-vue';

const previewData = ref(null);
const importResult = ref(null);
const skipDuplicates = ref(true);
const previewing = ref(false);
const importing = ref(false);
const selectedFileName = ref('');
const bookmarkFile = shallowRef(null);
const previewRequestId = ref(0);
const maxFileSize = 10 * 1024 * 1024;
const canImport = computed(() => Boolean(previewData.value?.total_bookmarks && bookmarkFile.value && !importing.value && !previewing.value));
const uploadStatusText = computed(() => {
  if (importing.value) return '正在导入书签...';
  if (previewing.value) return '正在解析书签文件...';
  return '点击或拖拽上传书签文件';
});
const selectedFileSize = computed(() => bookmarkFile.value ? formatFileSize(bookmarkFile.value.size) : '');
const uploadAssistText = computed(() => {
  if (importing.value) return '导入进行中，请不要刷新或关闭页面。';
  if (previewing.value) return '正在读取文件并生成预览，完成后即可确认导入。';
  if (selectedFileName.value) return '文件已选择，正在等待解析结果。';
  return '导入后会自动归类为前台可见的实用二级分类。';
});
const importStats = computed(() => {
  const result = importResult.value?.result;
  if (!result) return [];
  return [
    { label: '新增分类', value: result.categories ?? 0 },
    { label: '新增站点', value: result.sites ?? 0 },
    { label: '跳过重复', value: result.skipped ?? 0 },
  ];
});

function formatFileSize(size) {
  if (!Number.isFinite(size)) return '';
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

function isValidBookmarkFile(file, showMessage = true) {
  const name = file.name?.toLowerCase() || '';
  if (!name.endsWith('.html') && !name.endsWith('.htm')) {
    if (showMessage) message.warning('请上传 .html 或 .htm 格式的书签文件');
    return false;
  }

  if (file.size > maxFileSize) {
    if (showMessage) message.warning('书签文件不能超过 10MB');
    return false;
  }

  return true;
}

function beforeUpload(file) {
  if (!isValidBookmarkFile(file)) {
    bookmarkFile.value = null;
    selectedFileName.value = '';
    return false;
  }

  previewBookmarkFile(file);
  return false;
}

async function previewBookmarkFile(file) {
  const requestId = previewRequestId.value + 1;
  previewRequestId.value = requestId;
  bookmarkFile.value = file;
  selectedFileName.value = file.name || '';
  previewing.value = true;
  previewData.value = null;
  importResult.value = null;
  const formData = new FormData();
  formData.append('bookmark_file', file);
  try {
    const { data } = await request.post('/admin/api/bookmarks/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (requestId !== previewRequestId.value) return;
    previewData.value = data.data;
    message.success('解析成功');
  } catch {
    if (requestId !== previewRequestId.value) return;
    bookmarkFile.value = null;
    selectedFileName.value = '';
    // 错误提示已由 request 拦截器统一弹出
  } finally {
    if (requestId === previewRequestId.value) {
      previewing.value = false;
    }
  }
}

async function handleImport() {
  if (importing.value || previewing.value) return;

  if (!bookmarkFile.value || !previewData.value) {
    message.warning('请先上传并完成预览');
    return;
  }

  if (!previewData.value.total_bookmarks) {
    message.warning('没有可导入的书签');
    return;
  }

  importing.value = true;
  try {
    const formData = new FormData();
    formData.append('bookmark_file', bookmarkFile.value);
    const { data } = await request.post('/admin/api/bookmarks/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      params: { skip_duplicate: skipDuplicates.value },
    });
    importResult.value = data;
    previewData.value = null;
    bookmarkFile.value = null;
    selectedFileName.value = '';
    message.success('导入成功');
  } catch {
    // 错误提示已由 request 拦截器统一弹出
  } finally {
    importing.value = false;
  }
}

function clearImportState() {
  previewRequestId.value += 1;
  previewData.value = null;
  importResult.value = null;
  bookmarkFile.value = null;
  selectedFileName.value = '';
  previewing.value = false;
}

function resetPreview() { clearImportState(); }
function resetImport() { clearImportState(); skipDuplicates.value = true; }
</script>

<style scoped>
.bookmark-card { overflow: hidden; }

.toolbar-title {
  display: flex; align-items: center; gap: 8px;
  font-size: 16px; font-weight: 600;
  color: var(--admin-card-foreground);
}

.toolbar-title :deep(.anticon) { color: var(--admin-primary); }

.toolbar-hint { font-size: 13px; color: var(--admin-muted-foreground); }

.bookmark-body { padding: 24px; }

.upload-section :deep(.ant-upload-drag) {
  background: var(--admin-card);
  border-color: var(--admin-border);
}

.upload-section :deep(.ant-upload-drag:hover) { border-color: var(--admin-primary); }

.upload-section :deep(.ant-upload-disabled) { cursor: wait; }

.upload-text {
  font-size: 16px; font-weight: 600;
  color: var(--admin-card-foreground);
  margin-bottom: 8px;
}

.upload-hint { font-size: 13px; color: var(--admin-muted-foreground); }

.upload-status {
  margin: 8px 0 0;
  color: var(--admin-muted-foreground);
  font-size: 12px;
}

.upload-file {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  max-width: min(520px, 100%);
  margin: 2px 0 8px;
  padding: 5px 12px;
  border-radius: 999px;
  background: rgba(22, 119, 255, 0.08);
  color: var(--admin-primary);
  font-size: 12px;
  font-weight: 600;
  word-break: break-all;
}

.upload-file span:first-child {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.preview-section { margin-top: 8px; }

.preview-alert { margin-bottom: 16px; }

.skip-checkbox { margin: 0 0 16px; }

.preview-stat {
  text-align: center; padding: 16px;
  background: var(--admin-muted); border-radius: var(--admin-radius);
  margin-bottom: 16px;
}

.preview-stat-value {
  font-size: 32px; font-weight: 700; color: var(--admin-primary);
}

.preview-stat-label {
  font-size: 13px; color: var(--admin-muted-foreground); margin-top: 4px;
}

.folder-preview { margin-top: 16px; }
.folder-item {
  margin-bottom: 12px; padding: 12px;
  background: var(--admin-muted); border-radius: var(--admin-radius-md);
}
.folder-name {
  font-weight: 600; font-size: 14px;
  color: var(--admin-card-foreground); margin-bottom: 6px;
}
.folder-tags { display: flex; flex-wrap: wrap; gap: 4px; }
.more-hint { color: var(--admin-muted-foreground); font-size: 12px; align-self: center; }

.preview-actions {
  margin-top: 20px; display: flex;
  justify-content: flex-end; gap: 8px;
}

.result-stats {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.result-stat {
  min-width: 108px;
  padding: 12px 16px;
  border-radius: var(--admin-radius-md);
  background: var(--admin-muted);
  text-align: center;
}

.result-stat strong {
  display: block;
  color: var(--admin-primary);
  font-size: 22px;
  line-height: 1;
}

.result-stat span {
  display: block;
  margin-top: 6px;
  color: var(--admin-muted-foreground);
  font-size: 12px;
}
</style>
