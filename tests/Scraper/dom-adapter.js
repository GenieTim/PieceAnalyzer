const fs = require('fs');
const htmlparser2 = require('htmlparser2');
const cssSelect = require('css-select');
const domutils = require('domutils');

function wrapNode(node) {
    if (!node) return null;
    return {
        _raw: node,
        get textContent() { return domutils.textContent(node); },
        get innerText() { return domutils.textContent(node); },
        getAttribute(attr) { return node.attribs ? (node.attribs[attr] ?? null) : null; },
        querySelector(selector) {
            const found = cssSelect.selectOne(selector, node);
            return wrapNode(found);
        },
        querySelectorAll(selector) {
            const found = cssSelect.selectAll(selector, node);
            return found.map(wrapNode);
        },
        closest(selector) {
            let curr = node.parent;
            while (curr) {
                if (cssSelect.is(curr, selector)) return wrapNode(curr);
                curr = curr.parent;
            }
            return null;
        }
    };
}

function parseHTML(html) {
    const dom = htmlparser2.parseDocument(html);
    const titleNode = cssSelect.selectOne('title', dom);
    return {
        title: titleNode ? domutils.textContent(titleNode) : '',
        querySelector(selector) {
            const found = cssSelect.selectOne(selector, dom);
            return wrapNode(found);
        },
        querySelectorAll(selector) {
            const found = cssSelect.selectAll(selector, dom);
            return found.map(wrapNode);
        }
    };
}

function loadFixture(relativePath) {
    const fullPath = require('path').resolve(__dirname, '../../', relativePath);
    const html = fs.readFileSync(fullPath, 'utf8');
    return parseHTML(html);
}

module.exports = {
    parseHTML,
    loadFixture
};
