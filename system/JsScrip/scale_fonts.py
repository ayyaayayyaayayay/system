import os
import re

directory = os.path.join(os.path.dirname(__file__), '..', 'css')
scale_factor = 0.9 # 10% smaller

def scale_value(match):
    val = match.group(1)
    unit = match.group(2)
    num = float(val)
    scaled = round(num * scale_factor)
    return f"{scaled}{unit}"

def process_file(file_path):
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Scale font-size
    content = re.sub(r'font-size:\s*(\d+(?:\.\d+)?)(px|rem|em)', 
                     lambda m: f"font-size: {scale_value(m)}", content)
    
    # Scale line-height
    content = re.sub(r'line-height:\s*(\d+(?:\.\d+)?)(px|rem|em)', 
                     lambda m: f"line-height: {scale_value(m)}", content)

    # Scale min-height
    def min_height_replacer(m):
        num = float(m.group(1))
        if 0 < num < 100:
            return f"min-height: {scale_value(m)}"
        return m.group(0)
    
    content = re.sub(r'min-height:\s*(\d+(?:\.\d+)?)(px)', min_height_replacer, content)

    # Scale height
    def height_replacer(m):
        num = float(m.group(1))
        if 20 < num < 100:
            return f"height: {scale_value(m)}"
        return m.group(0)
    
    content = re.sub(r'height:\s*(\d+(?:\.\d+)?)(px)', height_replacer, content)

    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f"Processed: {os.path.basename(file_path)}")

def main():
    if not os.path.exists(directory):
        print(f"Directory {directory} not found.")
        return
        
    for file in os.listdir(directory):
        if file.endswith('.css'):
            process_file(os.path.join(directory, file))
    print('Done scaling fonts and layout properties!')

if __name__ == '__main__':
    main()
