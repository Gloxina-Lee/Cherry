import { importExternal } from '../common/npmLib'

declare global {
    interface Window {
        Typed: any
    }
}

let typedInstance: import('typed.js').default
let generation = 0
let pendingRequest: AbortController | null = null
let nextSentenceTimer: ReturnType<typeof setTimeout> | null = null

interface HitokotoConfig {
    enabled: boolean
    url: string
    loop: boolean
    typeSpeed: number
    backSpeed: number
    showCursor: boolean
}

async function loadTyped(): Promise<typeof import('typed.js').default> {
    if (_iro.ext_shared_lib) {
        if (!window.Typed) await importExternal('dist/typed.umd.js', 'typed.js')
        return window.Typed
    }
    const { default: Typed } = await import('typed.js')
    return Typed
}

function normalizeSpeed(value: number, fallback: number): number {
    const speed = Number(value)
    return Number.isFinite(speed) && speed >= 0 ? Math.min(Math.floor(speed), 1000) : fallback
}

export function disableTypedJsIfExist() {
    generation++
    if (nextSentenceTimer) {
        clearTimeout(nextSentenceTimer)
        nextSentenceTimer = null
    }
    if (pendingRequest) {
        pendingRequest.abort()
        pendingRequest = null
    }
    if (typedInstance) {
        typedInstance.destroy()
        typedInstance = null
    }
}

export default async function initTypedJs() {
    const json = document.getElementById('typed-js-initial')
    disableTypedJsIfExist() // Cancel an outstanding Hitokoto request on PJAX navigation.
    if (!json) return

    const currentGeneration = generation
    const element = document.querySelector<HTMLElement>('.header-info .element')
    if (!element) return

    try {
        const configNode = document.getElementById('hitokoto-config')
        const config = configNode ? JSON.parse(configNode.textContent || '{}') as HitokotoConfig : null
        if (config?.enabled) {
            const endpoint = new URL(config.url, document.baseURI)
            if (endpoint.protocol !== 'http:' && endpoint.protocol !== 'https:') {
                throw new Error('Invalid Hitokoto API URL')
            }

            const startSentence = async () => {
                const request = new AbortController()
                pendingRequest = request
                try {
                    const response = await fetch(endpoint.href, {
                        signal: request.signal,
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                    })
                    if (!response.ok) throw new Error(`Hitokoto API returned ${response.status}`)
                    const data: unknown = await response.json()
                    if (!data || typeof data !== 'object' || !('hitokoto' in data) ||
                        typeof data.hitokoto !== 'string' || !data.hitokoto.trim()) {
                        throw new Error('Hitokoto API response is missing hitokoto text')
                    }
                    const sentence = data.hitokoto.trim()
                    const Typed = await loadTyped()
                    if (currentGeneration !== generation || request.signal.aborted) return

                    // Typed.js backspaces the current text before typing the next sentence.
                    const previousSentence = typedInstance ? element.textContent : ''
                    if (typedInstance) typedInstance.destroy()
                    element.textContent = previousSentence || ''
                    typedInstance = new Typed(element, {
                        strings: [sentence],
                        typeSpeed: normalizeSpeed(config.typeSpeed, 140),
                        backSpeed: normalizeSpeed(config.backSpeed, 50),
                        showCursor: config.showCursor,
                        contentType: 'text',
                        loop: false,
                        onComplete: () => {
                            if (config.loop && currentGeneration === generation) {
                                nextSentenceTimer = setTimeout(startSentence, 2000)
                            }
                        },
                    })
                } catch (error) {
                    if (currentGeneration !== generation || request.signal.aborted) return
                    console.error('获取一言失败', error)
                    if (config.loop) nextSentenceTimer = setTimeout(startSentence, 10000)
                } finally {
                    if (pendingRequest === request) pendingRequest = null
                }
            }

            await startSentence()
            return
        }

        const options = JSON.parse(json.textContent || '')
        element.innerText = ''
        const Typed = await loadTyped()
        if (currentGeneration !== generation) return
        typedInstance = new Typed(element, options)
    } catch (error) {
        console.error('请检查typed.js设置', error)
    }
}
