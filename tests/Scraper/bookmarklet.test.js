const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function stripComments(js) {
    let output = '';
    const len = js.length;
    let i = 0;

    while (i < len) {
        const c = js[i];

        if (c === "'") {
            output += "'";
            i++;
            while (i < len && js[i] !== "'") {
                if (js[i] === '\\' && i + 1 < len) {
                    output += js[i] + js[i + 1];
                    i += 2;
                } else {
                    output += js[i++];
                }
            }
            if (i < len) output += js[i++];
        } else if (c === '"') {
            output += '"';
            i++;
            while (i < len && js[i] !== '"') {
                if (js[i] === '\\' && i + 1 < len) {
                    output += js[i] + js[i + 1];
                    i += 2;
                } else {
                    output += js[i++];
                }
            }
            if (i < len) output += js[i++];
        } else if (c === '`') {
            output += '`';
            i++;
            while (i < len && js[i] !== '`') {
                if (js[i] === '\\' && i + 1 < len) {
                    output += js[i] + js[i + 1];
                    i += 2;
                } else {
                    output += js[i++];
                }
            }
            if (i < len) output += js[i++];
        } else if (c === '/' && i + 1 < len && js[i + 1] === '/') {
            i += 2;
            while (i < len && js[i] !== '\n' && js[i] !== '\r') {
                i++;
            }
        } else if (c === '/' && i + 1 < len && js[i + 1] === '*') {
            i += 2;
            while (i + 1 < len && !(js[i] === '*' && js[i + 1] === '/')) {
                i++;
            }
            i += 2;
        } else if (c === '/' && i + 1 < len && js[i + 1] !== '/' && js[i + 1] !== '*') {
            let prev = '';
            for (let j = output.length - 1; j >= 0; j--) {
                if (!/\s/.test(output[j])) {
                    prev = output[j];
                    break;
                }
            }
            if (prev === '' || '=(:,;!&|?{}~+-*^['.includes(prev)) {
                output += '/';
                i++;
                let inClass = false;
                while (i < len) {
                    if (js[i] === '\\' && i + 1 < len) {
                        output += js[i] + js[i + 1];
                        i += 2;
                    } else if (js[i] === '[' && !inClass) {
                        inClass = true;
                        output += js[i++];
                    } else if (js[i] === ']' && inClass) {
                        inClass = false;
                        output += js[i++];
                    } else if (js[i] === '/' && !inClass) {
                        output += js[i++];
                        while (i < len && /[a-z]/i.test(js[i])) {
                            output += js[i++];
                        }
                        break;
                    } else {
                        output += js[i++];
                    }
                }
            } else {
                output += c;
                i++;
            }
        } else {
            output += c;
            i++;
        }
    }
    return output;
}

test('Bookmarklet engine: compiles and executes cleanly in VM without syntax errors', () => {
    const extractorPath = path.resolve(__dirname, '../../assets/js/scraper/extractor.js');
    const extractorJs = fs.readFileSync(extractorPath, 'utf8');

    const cleanExtractor = stripComments(extractorJs);
    const apiUrl = 'https://piece-analyzer.test/api/price-report';
    const apiToken = 'token_test_123';

    const runnerJs = `
(function(){
    ${cleanExtractor}

    var extractor = (typeof LegoPriceExtractor !== 'undefined') ? LegoPriceExtractor : ((typeof window !== 'undefined' && window.LegoPriceExtractor) ? window.LegoPriceExtractor : ((typeof self !== 'undefined' && self.LegoPriceExtractor) ? self.LegoPriceExtractor : {}));
    var items = (typeof extractor.extractAll === 'function') ? extractor.extractAll(document, window.location.href) : [];
    
    globalThis.__test_result = {
        extractorLoaded: typeof extractor.extractAll === 'function',
        itemsCount: items.length
    };
})();
`;

    // 1. Verify syntax via new vm.Script (throws SyntaxError if invalid)
    let script;
    assert.doesNotThrow(() => {
        script = new vm.Script(runnerJs);
    }, 'Bookmarklet script must compile without SyntaxError');

    // 2. Execute in mock DOM context
    const sandbox = {
        window: {
            location: { href: 'https://www.lego.com/en-ch' }
        },
        document: {
            title: 'LEGO Store',
            querySelectorAll: () => []
        },
        globalThis: {}
    };
    sandbox.self = sandbox.window;

    const context = vm.createContext(sandbox);
    assert.doesNotThrow(() => {
        script.runInContext(context);
    }, 'Bookmarklet script must run without runtime exceptions');

    assert.equal(sandbox.globalThis.__test_result.extractorLoaded, true);
});
