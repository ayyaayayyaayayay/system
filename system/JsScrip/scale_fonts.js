const fs = require('fs');
const path = require('path');

const directory = __dirname;
const scaleFactor = 0.9; // 10% smaller

function scaleValue(match, value, unit) {
    const num = parseFloat(value);
    const scaled = Math.round(num * scaleFactor);
    return `${scaled}${unit}`;
}

function processFile(filePath) {
    let content = fs.readFileSync(filePath, 'utf8');
    
    // Scale font-size
    content = content.replace(/font-size:\s*(\d+(?:\.\d+)?)(px|rem|em)/g, (match, val, unit) => {
        return `font-size: ${scaleValue(match, val, unit)}`;
    });
    
    // Scale line-height if it has a unit (px, rem, em)
    content = content.replace(/line-height:\s*(\d+(?:\.\d+)?)(px|rem|em)/g, (match, val, unit) => {
        return `line-height: ${scaleValue(match, val, unit)}`;
    });

    // Optionally scale some fixed heights to accommodate text (e.g. min-height)
    content = content.replace(/min-height:\s*(\d+(?:\.\d+)?)(px)/g, (match, val, unit) => {
        // Only scale min-heights that seem like row/header heights (e.g. < 100px)
        const num = parseFloat(val);
        if (num > 0 && num < 100) {
            return `min-height: ${scaleValue(match, val, unit)}`;
        }
        return match;
    });

    content = content.replace(/height:\s*(\d+(?:\.\d+)?)(px)/g, (match, val, unit) => {
        // Scale small fixed heights (like headers, buttons)
        const num = parseFloat(val);
        if (num > 20 && num < 100) {
            return `height: ${scaleValue(match, val, unit)}`;
        }
        return match;
    });

    fs.writeFileSync(filePath, content, 'utf8');
    console.log(`Processed: ${path.basename(filePath)}`);
}

function main() {
    const files = fs.readdirSync(directory);
    const cssFiles = files.filter(f => f.endsWith('.css'));
    
    for (const file of cssFiles) {
        processFile(path.join(directory, file));
    }
    console.log('Done scaling fonts and layout properties!');
}

main();
