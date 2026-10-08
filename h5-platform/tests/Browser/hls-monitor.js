// Observe real hls.js errors; no replacement transport or decoder.
(() => {
  if (!window.Hls) return;
  const RealHls = window.Hls;
  window.qa06HlsErrors = [];
  window.Hls = class extends RealHls {
    constructor(config) {
      super(config);
      this.on(RealHls.Events.ERROR, (_event, d) => window.qa06HlsErrors.push({type:d.type, details:d.details, fatal:d.fatal, status:d.response?.code??null}));
    }
  };
})();
