<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * 解析浏览器导出的 Netscape Bookmark HTML 文件
 */
class BookmarkParserService
{
    /**
     * 解析书签HTML文件内容
     */
    public function parse(string $html): array
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }

        // 提取最外层DL内容
        if (! preg_match('/<DL>(.*)<\/DL>/si', $html, $match)) {
            return [];
        }

        return $this->parseItems($match[1]);
    }

    /**
     * 解析一组DT项（DL块的内容）
     */
    private function parseItems(string $content, int $depth = 0): array
    {
        // Prevent excessive recursion with deeply nested bookmarks
        if ($depth > 20) {
            return [];
        }

        $result = [];
        $offset = 0;
        $len = strlen($content);
        $maxIterations = 10000;
        $iteration = 0;

        while ($offset < $len) {
            if (++$iteration > $maxIterations) {
                break;
            }

            // 找下一个 <DT>
            $dtPos = stripos($content, '<DT>', $offset);
            if ($dtPos === false) {
                break;
            }

            $offset = $dtPos + 4;

            // 情况1: 文件夹 <H3> —— 必须紧跟当前 <DT>（只隔空白）。无界搜索
            // 会把后续兄弟文件夹误认成当前项，吞掉夹在中间的书签
            $h3Pos = stripos($content, '<H3', $offset);
            $leading = $h3Pos === false ? '' : substr($content, $offset, $h3Pos - $offset);
            if ($h3Pos !== false && trim($leading) === ''
                && preg_match('/<H3[^>]*>(.*?)<\/H3>/si', $content, $folderMatch, 0, $offset)) {
                $folderName = $this->cleanText($folderMatch[1]);
                if ($folderName) {
                    // Use PREG_OFFSET_CAPTURE to get accurate position
                    $afterH3 = $offset + strlen($folderMatch[0]);
                    $dlStart = stripos($content, '<DL>', $afterH3);

                    if ($dlStart !== false) {
                        $inner = $this->extractDLContent($content, $dlStart);
                        if ($inner !== null) {
                            $children = $this->parseItems($inner, $depth + 1);
                            $result[] = [
                                'type' => 'folder',
                                'name' => $folderName,
                                'children' => $children,
                            ];
                            // Skip past the closing </DL> tag - use stripos for case-insensitive match
                            $closePos = stripos($content, '</DL>', $dlStart + 4 + strlen($inner));
                            $offset = $closePos !== false ? $closePos + 5 : $dlStart + 4 + strlen($inner) + 5;

                            continue;
                        }
                    }

                    // 没有DL子块，空文件夹
                    $result[] = [
                        'type' => 'folder',
                        'name' => $folderName,
                        'children' => [],
                    ];
                }

                continue;
            }

            // 情况2: 书签 <A HREF="">
            if (preg_match('/<A\s+([^>]*)>(.*?)<\/A>/si', $content, $linkMatch, 0, $offset)) {
                $attrs = $linkMatch[1];
                $title = $this->cleanText($linkMatch[2]);
                $url = $this->sanitizeBookmarkUrl($this->extractUrl($attrs));

                if ($url) {
                    $addDate = null;
                    if (preg_match('/ADD_DATE="(\d+)"/si', $attrs, $m)) {
                        $addDate = date('Y-m-d H:i:s', (int) $m[1]);
                    }

                    $result[] = [
                        'type' => 'bookmark',
                        'title' => $title ?: $this->extractTitleFromUrl($url),
                        'url' => $url,
                        'add_date' => $addDate,
                    ];
                }
                $offset += strlen($linkMatch[0]);
            } else {
                // No match found, advance to avoid infinite loop
                $offset += 1;
            }
        }

        return $result;
    }

    /**
     * 提取<DL>...</DL>之间的内容，正确处理嵌套
     */
    private function extractDLContent(string $content, int $dlStart): ?string
    {
        $depth = 0;
        $pos = $dlStart;
        $len = strlen($content);

        while ($pos < $len) {
            $openTag = stripos($content, '<DL>', $pos);
            $closeTag = stripos($content, '</DL>', $pos);

            if ($closeTag === false) {
                break;
            }

            if ($openTag !== false && $openTag < $closeTag) {
                $depth++;
                $pos = $openTag + 4;
            } else {
                $depth--;
                if ($depth === 0) {
                    // 找到匹配的闭合标签
                    $innerStart = stripos($content, '>', $dlStart) + 1;

                    return substr($content, $innerStart, $closeTag - $innerStart);
                }
                $pos = $closeTag + 5;
            }
        }

        return null;
    }

    /**
     * 从属性字符串中提取URL
     */
    private function extractUrl(string $attrs): string
    {
        if (preg_match('/HREF="([^"]*)"/si', $attrs, $m)) {
            return $m[1];
        }
        if (preg_match("/HREF='([^']*)'/si", $attrs, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Decode HTML entities and allow only http(s) schemes.
     * Browsers decode entities when navigating, so "JAVASCRIPT:" or
     * "&#106;avascript:..." variants must be rejected the same way.
     */
    private function sanitizeBookmarkUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /**
     * 生成导入预览数据
     */
    public function preview(string $html): array
    {
        $tree = $this->parse($html);
        $flat = $this->flatten($tree);

        $stats = ['total_bookmarks' => 0, 'total_folders' => 0];
        $preview = [];

        foreach ($flat as $group) {
            $count = count($group['bookmarks']);
            $stats['total_bookmarks'] += $count;

            if ($group['folder']) {
                $stats['total_folders']++;
                $preview[] = [
                    'folder' => $group['folder'],
                    'parent_folder' => $group['parent_folder'],
                    'count' => $count,
                    'samples' => array_slice($group['bookmarks'], 0, 3),
                ];
            } else {
                // collect()->first() returns a copy — mutating it never writes
                // back into $preview, so the counts were silently lost
                $existingIndex = null;
                foreach ($preview as $i => $p) {
                    if ($p['folder'] === '未分类书签') {
                        $existingIndex = $i;
                        break;
                    }
                }

                if ($existingIndex !== null) {
                    $preview[$existingIndex]['count'] += $count;
                    $preview[$existingIndex]['samples'] = array_slice(
                        array_merge($preview[$existingIndex]['samples'], $group['bookmarks']), 0, 3
                    );
                } else {
                    $preview[] = [
                        'folder' => '未分类书签',
                        'parent_folder' => null,
                        'count' => $count,
                        'samples' => array_slice($group['bookmarks'], 0, 3),
                    ];
                }
            }
        }

        return ['stats' => $stats, 'preview' => $preview, 'tree' => $tree];
    }

    /**
     * 将解析树展平为分组列表
     */
    public function flatten(array $tree, ?string $parentFolder = null): array
    {
        $result = [];

        foreach ($tree as $item) {
            if ($item['type'] === 'folder') {
                $folderPath = $parentFolder ? $parentFolder.' / '.$item['name'] : $item['name'];

                // Recurse into subfolders ONLY — the folder's direct bookmarks
                // are collected below, and recursing over them too would emit
                // each one twice (guaranteed unique-key collisions on import)
                $subfolders = array_values(array_filter(
                    $item['children'],
                    fn ($c) => $c['type'] === 'folder',
                ));
                $result = array_merge($result, $this->flatten($subfolders, $folderPath));

                // 收集当前文件夹的直接书签
                $bookmarks = array_values(array_filter(
                    $item['children'],
                    fn ($c) => $c['type'] === 'bookmark',
                ));
                if (! empty($bookmarks)) {
                    $result[] = [
                        'folder' => $item['name'],
                        'parent_folder' => $parentFolder,
                        'bookmarks' => $bookmarks,
                    ];
                }
            } elseif ($item['type'] === 'bookmark') {
                $result[] = [
                    'folder' => null,
                    'parent_folder' => null,
                    'bookmarks' => [$item],
                ];
            }
        }

        return $result;
    }

    /**
     * 执行导入
     */
    public function import(string $html, array $options = []): array
    {
        $tree = $this->parse($html);
        $flat = $this->flatten($tree);

        $imported = ['categories' => 0, 'sites' => 0, 'skipped' => 0];

        if (empty($flat)) {
            return $imported;
        }

        $skipDuplicate = $options['skip_duplicate'] ?? true;
        $functionalMode = ($options['import_mode'] ?? null) === 'functional';
        $parentCategoryId = $options['parent_category_id'] ?? null;
        $existingUrls = Site::pluck('url')->flip()->toArray();
        $pathCache = [];
        $categoryCache = [];

        // Pre-calculate next sort_order to avoid repeated MAX() queries
        $nextSortOrder = (Category::max('sort_order') ?? 0) + 1;

        if ($functionalMode && ! $parentCategoryId) {
            $rootCategory = $this->firstOrCreateCategory('导入书签', null, $imported, $nextSortOrder, $categoryCache, [
                'icon' => 'io-quanbu-qitafenlei',
                'sort_order' => 0,
            ]);
            $parentCategoryId = $rootCategory->id;
        }

        foreach ($flat as $group) {
            if (empty($group['bookmarks'])) {
                continue;
            }

            if ($functionalMode) {
                $catParentId = $parentCategoryId;
            } else {
                // Resolve the parent chain by walking the "A / B" folder path —
                // a name lookup would never match for nested folders (and could
                // match a same-named category at the wrong depth)
                $catParentId = $this->resolveOrCreatePath(
                    $group['parent_folder'],
                    $parentCategoryId,
                    $pathCache,
                    $imported,
                    $nextSortOrder,
                );
            }

            foreach ($group['bookmarks'] as $bookmark) {
                $categoryName = $functionalMode ? $this->classifyBookmark($bookmark) : ($group['folder'] ?: '未分类书签');
                $category = $this->firstOrCreateCategory($categoryName, $catParentId, $imported, $nextSortOrder, $categoryCache);
                $url = mb_substr($bookmark['url'], 0, 255);

                if ($skipDuplicate && isset($existingUrls[$url])) {
                    $imported['skipped']++;

                    continue;
                }

                try {
                    Site::create([
                        'category_id' => $category->id,
                        'title' => $bookmark['title'],
                        'url' => $url,
                        'description' => '',
                        'favicon_url' => $this->faviconUrl($url),
                        'is_public' => true,
                        'is_active' => true,
                        'sort_order' => 0,
                    ]);

                    $existingUrls[$url] = true;
                    $imported['sites']++;
                } catch (UniqueConstraintViolationException) {
                    // sites.url has a UNIQUE index — with skip_duplicate off a
                    // duplicate must be counted and skipped, not roll back the
                    // whole import transaction
                    $imported['skipped']++;

                    continue;
                }
            }
        }

        return $imported;
    }

    private function firstOrCreateCategory(string $name, ?int $parentId, array &$imported, int &$nextSortOrder, array &$categoryCache = [], array $attributes = []): Category
    {
        $cacheKey = ($parentId ?? 'root').':'.$name;
        if (isset($categoryCache[$cacheKey])) {
            return $categoryCache[$cacheKey];
        }

        $categoryQuery = Category::where('name', $name);
        if ($parentId) {
            $categoryQuery->where('parent_id', $parentId);
        } else {
            $categoryQuery->whereNull('parent_id');
        }
        $category = $categoryQuery->first();

        if (! $category) {
            $category = $this->createCategoryWithRetry($name, $parentId, $imported, $nextSortOrder, $attributes);
        } elseif ($attributes) {
            $category->forceFill($attributes)->save();
        }

        $categoryCache[$cacheKey] = $category;

        return $category;
    }

    private function classifyBookmark(array $bookmark): string
    {
        $text = strtolower($bookmark['title'].' '.$bookmark['url']);

        $groups = $this->functionalGroups();

        foreach ($groups as $name => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, strtolower($keyword))) {
                    return $name;
                }
            }
        }

        return '其他收藏';
    }

    public static function functionalGroups(): array
    {
        return [
            'AI 工具与模型' => ['chatgpt', 'claude', 'poe.com', 'kimi', 'deepseek', 'chatglm', '智谱', 'doubao', 'qwen', '通义', '文心', 'minimax', 'perplexity', 'coze', 'ollama', 'maton', 'clawhub', 'ddcatai', 'fogsight', 'huggingface', 'modelscope', 'siliconflow', 'bigmodel', 'openai', 'anthropic', 'gemini', 'ai ', 'ai-', 'aigc', 'prompt', '提示词', '大模型', '模型', '智能体'],
            'AI 编程与在线开发' => ['cursor', 'codebuddy', 'github copilot', 'copilot', 'bolt.new', 'v0.dev', 'replit', 'trae', 'same.new', 'stackblitz', 'codesandbox', 'coding.net', 'ai 编程'],
            '搜索导航与资讯' => ['google.', 'bing.', 'baidu.', 'duckduckgo', 'yandex', 'sogou', '360.cn', 'search', 'fsou', 'tophub', 'kadaza', 'readfar', 'wanandroid', '528btc', 'echotik', '搜索', '导航', '今日热榜', '资讯'],
            '翻译语言与学习' => ['translate', 'fanyi', 'deepl', 'youdao', 'cpta', 'zikao', 'eeagd', 'jiaoshi', 'ouchn', 'hqjy', 'course', 'learn', 'summerclassical', '有道翻译', '谷歌翻译', '翻译', '考试', '自考', '教育', '网校', '学习'],
            '开发文档与教程' => ['docs.', 'developer.', 'tutorial', 'mdn', 'w3school', 'runoob', 'kancloud', 'roadmap.sh', 'free-for.dev', 'modelcontextprotocol', 'prismic', 'harmonyos', 'element.eleme.cn', 'zhinin', '文档', '教程', '手册'],
            '前端开发' => ['vue', 'react', 'angular', 'svelte', 'element-plus', 'element.eleme', 'antdv', 'naive', 'vant', 'vite', 'webpack', 'parcel', 'tailwind', 'bootstrap', 'css', 'html', 'javascript', 'typescript', 'nodejs', 'npm', 'pnpm', 'yarn', 'gsap', 'taro', 'uni-app', 'three.js', 'webgl', 'animxyz', 'wow.js', 'madewith', 'fff.cmiscm', 'gridea', '前端', '组件库'],
            '后端接口与数据库' => ['php', 'laravel', 'java', 'spring', 'python', 'django', 'flask', 'go.dev', 'golang', 'rust', 'api', 'mysql', 'postgres', 'postgresql', 'redis', 'mongodb', 'mongoose', 'sqlite', 'mock.js', 'easy mock', '接口', '后端', '数据库', '缓存'],
            '代码托管与开源社区' => ['github', 'gitlab', 'gitee', 'bitbucket', 'stackoverflow', 'stack overflow', 'packagist', 'npmjs', 'openatom', 'openharmony', 'openi', 'osrc', '开源', '代码'],
            '技术博客与社区' => ['csdn', 'juejin', 'cnblogs', 'jianshu', 'segmentfault', 'hashnode', '掘金', '博客园', '简书', '技术博客'],
            '建站 CMS 与商城系统' => ['wordpress', 'drupal', 'joomla', 'cms', 'woocommerce', 'elementor', 'crmeb', 'beikeshop', 'kadence', 'wpgraphql', 'thinkcmf', 'shopify', 'amazon', 'aliexpress', 'dhgate', 'ebay', 'etsy', '1688', 'taobao', 'tmall', 'maoshop', '大白杂货铺', '淘宝', '天猫', '建站', '商城', '电商', '主题', '插件'],
            '后台低代码与业务系统' => ['admin', 'naiveadmin', 'likeadmin', 'lowcode', 'low-code', 'ruoyi', 'magic-boot', 'form-create', 'vform', 'configureid', 'vika.cn', 'zion', 'axshare', 'inffni', 'inffnisys', 'moredian', 'glee.firefliesnight', 'dzx.os.gs.cn', 'shengwang', '声网', '工单', '商家管理', '个人中心', '后台管理', '低代码', '后台模板', '管理后台', '动态表单', '表单'],
            '云服务器与部署' => ['aws', 'azure', 'google cloud', 'aliyun', '腾讯云', 'cloud.tencent', 'qiniu', 'cloudflare', 'vultr', 'volcengine', '3v.do', 'free.3v', '49.51.', 'vercel', 'netlify', 'server', 'vps', 'host', '主机', '云服务', '部署'], 
            '运维安全与域名' => ['docker', 'docker hub', 'kubernetes', 'jenkins', 'nginx', 'apache', 'linux', 'ssl', 'dns', 'domain', 'whois', 'httpstatus', 'myip', 'idcicp', '1panel', 'lpanel', '域名', '运维', '安全'],
            '在线工具与效率' => ['json.cn', 'json', 'tinyjpg', 'pagespeed', 'staticfile', 'cdn', 'compress', 'format', 'gradient', 'shapefactory', 'ip138', 'jiejingku', 'dayin.page', 'baominggongju', '格式化', '压缩', '在线工具', '查询', '打印', '快捷指令'],
            '设计素材与原型' => ['figma', 'mastergo', 'lanhuapp', 'sketch', 'mockplus', 'framer', 'albacub', 'iconfont', 'icon', 'font', 'dribbble', 'behance', 'wallspic', 'ui', 'ux', 'design', '原型', '设计协作', '蓝湖', '壁纸', '素材', '图标', '字体', '设计'],
            '办公协作与知识库' => ['notion', 'trello', 'jira', 'asana', 'clickup', 'yuque', 'project', 'microsoft', 'volta', 'zoho', 'contentful', '腾讯会议', 'meeting.tencent', '会议', '录制文件', '知识库', '协作', '项目'],
            '邮箱通讯与客服 CRM' => ['slack', 'dingtalk', 'feishu', 'lark', 'mail.', 'mailchimp', 'gmail', 'outlook.live', '126.com', '163.com', 'qq.com', 'brevo', 'tidio', 'crm', 'forms', 'support.', 'help center', 'portal', '飞书', '钉钉', '邮箱', '客服'], 
            'SEO 统计与营销' => ['seo', 'semrush', 'ahrefs', 'similarweb', 'search console', 'analytics', 'google ads', 'adwords', 'adsense', 'facebook ads', 'pixel', 'tag manager', 'tongji', '站长', '收录', '关键词', '流量', '统计', '投放', '广告', '营销'],
            '支付金融与 Web3' => ['paypal', 'stripe', 'payoneer', 'wise.com', 'worldfirst', 'metamask', 'opensea', 'nft', 'crypto', 'bayc', 'blockpulse', 'za bank', 'alipay', 'wechatpay', '支付', '收款', '金融'],
            '政务法律与企业服务' => ['gov.cn', 'yidaiyilu', 'inanshan', 'szyouth', 'qixin', '企查查', '天眼查', 'yuanpailaw', 'minglvshi', '律师', '法律', '工商', '企业查询', '政务', '公共服务', '人才服务'],
            '影音社交与内容平台' => ['bilibili', 'youtube', 'douyin', 'tiktok', 'vimeo', 'spotify', 'podcast', 'twitter', 'x.com', 'facebook', 'instagram', 'discord', 'zhihu', 'weibo', 'xiaohongshu', 'ferryboat', 'fairy.id', 'sunwk', '摆渡人', '西游', '江湖', '小红书', '知乎', '微博', '直播', '视频', '音频', '社交', '内容', '博客'],
            '生活娱乐与票务' => ['damai', 'jhzajc', 'qiumi', 'halihali', 'pzds', 'pnyouying', '有小象', 'komc', '科麦', '游戏账号', '虚拟财产', '游戏交易', '影视', '演出', '赛事', '票务', '生活'],
            '文件传输与软件下载' => ['cowtransfer', 'msdn', 'itellyou', 'macwk', 'macyy', 'xclient', 'macbl', '52pojie', '吾爱破解', 'weiyun', 'download', '软件下载', '文件传输', '快传', '破解软件', 'mac应用'], 
            '网络代理与本地服务' => ['clash', 'veee', 'mielink', 'bananapro', 'jike491', '192.168.', '172.16.', 'localhost', '127.0.0.1', '101.200.', '43.139.', '81.71.', '233.gr', 'oa.', '机场', '代理', '加速', '内网', '本地'], 
            '硬件设备与地图可视化' => ['bambulab', 'dobot', 'robot', 'arduino', 'raspberry', 'ifixit', 'mapbox', 'amap', 'baidu map', 'google maps', 'echarts', 'd3', '树莓派', '硬件', '机器人', '3d printer', '修理', '地图', '地理', '可视化'],
            '外包招聘与副业' => ['upwork', 'epwk', 'yuanjisong', 'proginn', 'freelance', '17yongai', '招聘', '外包', '接私活', '兼职', '副业'],
        ];
    }

    private function faviconUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return 'https://t2.gstatic.cn/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&size=64&url='.urlencode('https://'.$host);
    }

    /**
     * Resolve a "A / B" folder path into a category id, creating any missing
     * category along the way. $pathCache memoizes lookups within one import.
     */
    private function resolveOrCreatePath(?string $path, ?int $rootParentId, array &$pathCache, array &$imported, int &$nextSortOrder): ?int
    {
        if (empty($path)) {
            return $rootParentId;
        }

        if (isset($pathCache[$path])) {
            return $pathCache[$path];
        }

        $separator = strrpos($path, ' / ');
        if ($separator === false) {
            $parentPath = null;
            $name = $path;
        } else {
            $parentPath = substr($path, 0, $separator);
            $name = substr($path, $separator + 3);
        }

        $parentId = $this->resolveOrCreatePath($parentPath, $rootParentId, $pathCache, $imported, $nextSortOrder);

        $categoryQuery = Category::where('name', $name);
        if ($parentId) {
            $categoryQuery->where('parent_id', $parentId);
        } else {
            $categoryQuery->whereNull('parent_id');
        }
        $category = $categoryQuery->first();

        if (! $category) {
            $category = $this->createCategoryWithRetry($name, $parentId, $imported, $nextSortOrder);
        }

        $pathCache[$path] = $category->id;

        return $category->id;
    }

    /**
     * Create a category, retrying once with a fresh slug on a slug collision.
     *
     * Slug generation is non-atomic (check-then-insert) and categories.slug
     * has a UNIQUE index — under concurrent imports two transactions can pick
     * the same slug, and the retry keeps one collision from rolling back the
     * whole import transaction.
     */
    private function createCategoryWithRetry(string $name, ?int $parentId, array &$imported, int &$nextSortOrder, array $attributes = []): Category
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $category = Category::create(array_merge([
                    'name' => $name,
                    'slug' => $this->generateSlug($name),
                    'is_active' => true,
                    'sort_order' => $nextSortOrder++,
                    'parent_id' => $parentId,
                ], $attributes));
                $imported['categories']++;

                return $category;
            } catch (UniqueConstraintViolationException $e) {
                // Only a slug collision may be retried; anything else rethrows
                // to abort the transaction.
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('unreachable');
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function generateSlug(string $name): string
    {
        // 中文友好的slug: 使用拼音首字母
        $slug = preg_replace('/[^\p{Han}\p{L}\p{N}\p{P}]+/u', '-', $name);
        if (empty($slug) || ! preg_match('/[a-zA-Z]/', $slug)) {
            $slug = 'cat-'.Str::random(6);
        }

        // 确保唯一
        $count = Category::where('slug', $slug)->count();
        if ($count > 0) {
            $slug .= '-'.Str::random(4);
        }

        return $slug;
    }

    private function extractTitleFromUrl(string $url): string
    {
        $parsed = parse_url($url);

        return str_replace('www.', '', $parsed['host'] ?? $url);
    }
}
