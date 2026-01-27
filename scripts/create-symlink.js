#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const readline = require('readline');

const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout
});

function question(query) {
  return new Promise(resolve => rl.question(query, resolve));
}

async function createSymlink() {
  try {
    // Get current plugin directory (where package.json is)
    const pluginDir = process.cwd();
    const pluginName = path.basename(pluginDir);

    console.log('\n🔗 Create Plugin Symlink\n');
    console.log(`Plugin: ${pluginName}`);
    console.log(`Plugin directory: ${pluginDir}\n`);

    // Ask for target directory
    const targetDir = await question('📁 Enter the full path to the WordPress directory (e.g. /Users/carmo/Sites/my-site/wp-content/plugins): ');

    if (!targetDir || !targetDir.trim()) {
      console.log('❌ Directory cannot be empty!');
      rl.close();
      return;
    }

    const targetPath = path.resolve(targetDir.trim());
    const symlinkPath = path.join(targetPath, pluginName);

    // Check if the target directory exists
    if (!fs.existsSync(targetPath)) {
      console.log(`❌ The directory ${targetPath} does not exist!`);
      rl.close();
      return;
    }

    // Check if a symlink or folder with the same name already exists
    if (fs.existsSync(symlinkPath)) {
      const stats = fs.lstatSync(symlinkPath);
      if (stats.isSymbolicLink()) {
        console.log(`⚠️  A symlink already exists at ${symlinkPath}`);
        const overwrite = await question('Do you want to overwrite it? (y/n): ');
        if (overwrite.toLowerCase() !== 'y') {
          console.log('❌ Operation cancelled.');
          rl.close();
          return;
        }
        fs.unlinkSync(symlinkPath);
      } else {
        console.log(`❌ A folder/file already exists at ${symlinkPath}`);
        console.log('❌ Cannot create the symlink. Please remove or rename it first.');
        rl.close();
        return;
      }
    }

    // Create the symlink
    fs.symlinkSync(pluginDir, symlinkPath, 'dir');
    
    console.log('\n✅ Symlink created successfully!');
    console.log(`   Source: ${pluginDir}`);
    console.log(`   Target: ${symlinkPath}\n`);

  } catch (error) {
    console.error('\n❌ Error creating symlink:', error.message);
  } finally {
    rl.close();
  }
}

createSymlink();
