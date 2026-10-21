// Content-only presentation of API failures; no retries or state changes.
(() => {
  'use strict';
  const messages = {
    STALE_VERSION: '内容已有新版本，请先核对最新内容，再决定如何保存；当前输入已保留。',
    UNSUPPORTED_VIDEO: '无法识别视频，请检查文件是否完整，并重新选择可播放的 MP4 文件。',
    VIDEO_DECODE_FAILED: '视频解码失败，请检查画面和音轨，重新导出可播放的 MP4 后上传。',
    INVALID_SUBTITLE: '字幕格式或时轴无效，请检查 SRT 编码、时间顺序及视频时长后重新上传。',
    INVALID_IMAGE: '图片无法处理，请重新选择完整的 PNG 或 JPEG 图片。',
    MEDIA_TOOL_UNAVAILABLE: '媒体处理工具暂不可用，请联系负责人检查服务后重试处理。',
    MEDIA_PROCESS_FAILED: '媒体处理失败，请检查文件；仍失败时请联系负责人核对处理服务。',
    PROCESSING_RETRY_EXHAUSTED: '媒体多次处理未完成，请联系负责人检查处理服务，再重试或更换文件。',
    FORBIDDEN: '当前账号没有操作权限，请联系负责人核对权限。',
    UNAUTHENTICATED: '员工会话已失效，请重新登录后核对操作结果。',
    VALIDATION_FAILED: '填写内容未通过检查，请核对必填项、格式和范围后再提交。'
  };
  const describe = (code, fallback = '') => (messages[code] || (/[\u3400-\u9fff]/.test(fallback) ? fallback : '操作未通过检查，请核对填写内容和当前状态后再提交。')) + (code ? `（${code}）` : '');
  const unknown = method => ['GET', 'HEAD'].includes(method)
    ? '未能读取最新状态，请保留输入并重新读取核对。'
    : '未确认操作结果，可能已保存，请先核对再重试。当前输入已保留。';
  async function request(url, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    let response, data;
    try { response = await fetch(url, options); } catch (_) { throw Error(unknown(method)); }
    try { data = await response.json(); } catch (_) { throw Error(unknown(method)); }
    if (!response.ok) {
      if (response.status >= 500) throw Error(unknown(method) + `（HTTP ${response.status}）`);
      const code = data.error?.code || ({401:'UNAUTHENTICATED',403:'FORBIDDEN',409:'STALE_VERSION',419:'UNAUTHENTICATED',422:'VALIDATION_FAILED'}[response.status]) || String(response.status);
      throw Error(describe(code, data.error?.message));
    }
    return data;
  }
  window.H5ContentErrors = { request, describe };
})();
