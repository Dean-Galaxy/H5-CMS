// Read-only metrics of the real player; never patches MediaSource, Hls, fetch, timers or video.
(() => {
  const v = document.querySelector('[data-player] video');
  if (!v) return;
  const out = document.createElement('output'); out.id='qa06-observation';
  out.style='display:block;white-space:pre-wrap;overflow-wrap:anywhere;font:12px monospace;background:#fff;color:#111;padding:8px';
  document.querySelector('main').append(out);
  setInterval(() => {
    const q=v.getVideoPlaybackQuality?.();
    out.textContent=JSON.stringify({ua:navigator.userAgent, nativeHls:v.canPlayType('application/vnd.apple.mpegurl'),
      forcedMse:window.qa06NativeHlsBefore!==undefined,nativeBefore:window.qa06NativeHlsBefore,hlsErrors:window.qa06HlsErrors,hlsVersion:window.Hls?.version,mseSupported:window.Hls?.isSupported?.(),currentTime:Number(v.currentTime.toFixed(2)),duration:v.duration,
      readyState:v.readyState,paused:v.paused,srcAttributePresent:v.hasAttribute('src'),networkState:v.networkState,sourceKind:v.currentSrc.startsWith('blob:')?'blob':v.currentSrc?'url':'empty',
      width:v.videoWidth,height:v.videoHeight,decodedFrames:q?.totalVideoFrames,error:v.error?.code??null,
      status:document.querySelector('[data-player-status]')?.textContent,observedAt:new Date().toISOString()});
  },500);
})();
