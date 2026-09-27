import lazyload from "../common/lazyload"
import { slideToggle } from "../common/util";
import { changeCoverBG, getCoverPath, getCurrentBG, nextBG, preBG } from "./coverBackground";
import { isMobile } from "./mobile";

/**
 * 设置前台背景
 * @returns 
 */
export async function changeSkin() {
    if (_iro.site_bg_as_cover) {
        changeCoverBG(await getCoverPath());
        return;
    }
    document.body.style.backgroundImage = _iro.skin_bg0 ? `url(${_iro.skin_bg0})` : '';
}

export function bgButtonAddListener() {
    const next = document.getElementById("bg-next"),
        pre = document.getElementById("bg-pre");
    if (next) { next.onclick = nextBG }
    if (pre) { pre.onclick = preBG }
}

/**
 * @has-dom-modify
 */
export function auto_height() {
    if (_iro.windowheight == 'auto') {
        if (_iro.land_at_home) {
            //let _height = document.documentElement.clientHeight + "px";
            const centerbg = document.getElementById("centerbg")
            if (centerbg) centerbg.style.height = "100vh";
        }
    } else {
        const headertop = document.querySelector(".headertop")
        headertop && headertop.classList.add("headertop-bar");
    }
}
/**
 * @has-dom-modify
 */
export function PE() {
    const headertop = document.querySelector(".headertop")
    if (headertop) {
        let blank = document.querySelector(".blank");
        if (_iro.land_at_home) {
            try {
                blank.style.paddingTop = "0px";
            } catch (e) { }
            headertop.style.height = "auto";
            headertop.style.display = "";
        } else {
            try {
                blank.style.paddingTop = "75px";
            } catch (e) { }
            headertop.style.height = "0px";
            headertop.style.display = "none";
        }
    }
}

import { turnOnDarkMode, turnOffDarkMode } from './darkmode';
/**
 * @has-dom-modify
 */
export function CE() {
    let comments_fold = document.querySelector(".comments-fold");
    let comments_main = document.querySelector(".comments-main");
    if (comments_fold != null) {
        comments_fold.style.display = "block";
        comments_main.style.display = "none";
        comments_fold.addEventListener("click", () => {
            slideToggle(comments_main, 500, 'show');
            comments_fold.style.display = "none";
        });
    }
    let archives = document.getElementsByClassName("archives");
    if (archives.length > 0) {
        for (let i = 1; i < archives.length; i++) {
            archives[i].style.display = "none";
        }
        archives[0].style.display = "";
        let h3 = document.getElementById("archives-temp").getElementsByTagName("h3");
        const handler = (e) => {
            e.preventDefault();
            e.stopPropagation();
            slideToggle(e.target.nextElementSibling, 300);
        }
        for (let i = 0; i < h3.length; i++) {
            h3[i].addEventListener("click", handler)
        }
    }

    try {
        const loading = document.getElementById("loading");
        loading.addEventListener("click", () => {
            loading.classList.add("hide");
            loading.classList.remove("show");
        });
    } catch (e) { }
}
//#endregion Siren
export function addSkinMenuListener() {
    const cached = document.querySelectorAll(".menu-list li");
    const handler = (e) => {
        const tagid = e.target.id || e.target.parentElement.id;
        if (tagid == "dark-bg") {
            turnOnDarkMode(true)
        } else {
            turnOffDarkMode(true)
            changeSkin()
        }
        closeSkinMenu();
    }
    for (const e of cached) {
        e.addEventListener("click", handler);
    }
}
/**
 * 根据当前配置应用前台背景。启用前台背景与站点封面背景一体化以后封面背景在此设置
 * @returns 一个Promise。Promise resolved 时封面背景应当已经加载完毕
 */
export function applyFrontendBackground() {
    return changeSkin();
}
export async function checkCoverBackground() {
    if (_iro.site_bg_as_cover) {
        return //交给 applyFrontendBackground 处理
    }
    if (!_iro.land_at_home) return//进入非主页  
    if (getCurrentBG()) {//进入主页且已经加载了封面背景
        return
    }
    changeCoverBG(await getCoverPath())
}
export function closeSkinMenu() {
    document.querySelector(".skin-menu").classList.remove("show");
    setTimeout(() => {
        const changeSkin = document.querySelector(".changeSkin-gear")
        if (changeSkin != null) {
            changeSkin.style.visibility = "visible";
        }
    }, 300);
}
