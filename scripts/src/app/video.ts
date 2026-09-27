import { importExternal } from '../common/npmLib';

declare global {
    interface Window {
        Hls: any
    }
    const Hls: any
}

export async function coverVideoIni() {
    initHLS()
    lazyloadPatch()
}
function canPlayHandler(this: HTMLVideoElement) {
    this.poster = ''
}
/**
 * 用户代理可能会禁止自动播放，此时需要撤掉poster
 */
async function lazyloadPatch() {
    document.querySelectorAll<HTMLVideoElement>('video.lazyload')
        .forEach(
            video => video.addEventListener('canplay', canPlayHandler)
        )
}
async function initHLS() {
    const videos = document.querySelectorAll<HTMLVideoElement>('video.hls');
    if (videos.length == 0) return
    //检查浏览器是否原生支持
    if (videos[0].canPlayType('application/vnd.apple.mpegurl')) {
        for (const video of videos) {
            video.src = video.dataset.src || video.src;
            video.autoplay = true
        }
    } else {
        if (!window.Hls) {
            try {
                if (_iro.ext_shared_lib) {
                    await importExternal('dist/hls.light.min.js', 'hls.js')
                } else {
                    //@ts-ignore
                    const { default: Hls } = await import('hls.js/dist/hls.light.js')
                    window.Hls = Hls
                }
            } catch (reason) {
                console.warn('Hls load failed: ', reason)
            }
        }
        if (!Hls.isSupported()) console.error('Hls: Media Source Extensions is unsupported.')
        for (const video of videos) {
            const hls = new Hls();
            hls.loadSource(video.dataset.src || video.src);
            hls.attachMedia(video);
            hls.on(Hls.Events.MANIFEST_PARSED, () => {
                video.play();
            });
        }
    }
}
