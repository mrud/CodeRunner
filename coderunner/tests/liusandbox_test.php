<?php
/**
 * Unit tests for coderunner's liusandbox class.
 * @group qtype_coderunner
 *
 * @package    qtype
 * @subpackage coderunner
 * @copyright  2012, 2013 Richard Lobb, University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;
require_once($CFG->dirroot . '/question/type/coderunner/Sandbox/liusandbox.php');

class qtype_coderunner_liusandbox_test extends basic_testcase {
    public function setUp() {
    }

    public function tearDown() {
    }

    public function test_testfunction() {
        $sandbox = new LiuSandbox();
        $tr = $sandbox->testFunction();
        $this->assertEquals(Sandbox::OK, $tr->error);
        $this->assertEquals(3.14, $tr->pi);
        $this->assertEquals(42, $tr->answerToLifeAndEverything);
        $this->assertTrue($tr->oOok);
        $langs = $sandbox->getLanguages();
        $langs = $langs->languages;
        $this->assertTrue(in_array('C', $langs, TRUE));
    }

    public function test_liu_sandbox_raw() {
        // Test the Python2 interface to the Liu sandbox directly, running
        // a C hello world program.
        global $CFG;
        $dirname = tempnam("/tmp", "coderunnertest_");
        unlink($dirname);
        mkdir($dirname);
        chdir($dirname);
        $handle = fopen('sourceFile', "w");
        fwrite($handle, "#include <stdio.h>\nint main() {\n    printf(\"Hello world\\nBlah\\n\");\n}\n");
        fclose($handle);
        $compile = "gcc -Wall -Werror -std=c99 -static -x c -o liusandboxtest sourceFile -lm 2>liuerrors";
        $output = array();
        exec($compile, $output, $returnVar);

        $run = array(
            'cmd' => array('liusandboxtest'),
            'input'  => '',
            'quota'  => array(
                'wallclock' => 30000,    // 30 secs
                'cpu'       => 5000,     // 5 secs
                'memory'    => 64000000, // 64MB
                'disk'      => 1048576   // 1 MB
            ),
            'workdir'      => $dirname,
            'readableDirs' => array()
        );

        $handle = fopen('runspec.json', "w");
        fwrite($handle, json_encode($run));
        fclose($handle);
        $output = array();
        $cmd = $CFG->dirroot . "/question/type/coderunner/Sandbox/liusandbox.py runspec.json";
        exec($cmd, $output, $returnVar);
        $outputJson = $output[0];
        $result = json_decode($outputJson);
        $this->assertEquals('OK', $result->returnCode);
        $this->assertEquals("Hello world\nBlah\n", $result->output);
        $this->assertEquals('', $result->stderr);
        chdir('..');
        $this->delTree($dirname);
    }

    // Test the liu sandbox with a syntactically bad C program
    public function test_liu_sandbox_bad_C() {
        $sandbox = new LiuSandbox();
        $code = "#include <stdio.h>\nint main(): {\n    printf(\"Hello sandbox\");\n    return 0;\n}\n";
        $result = $sandbox->execute($code, 'C', NULL);
        $this->assertEquals(Sandbox::RESULT_COMPILATION_ERROR, $result->result);
        $this->assertTrue(strpos($result->cmpinfo, 'error:') !== FALSE);
        $sandbox->close();
    }

    // Test the liu sandbox with a valid C program
    public function test_liu_sandbox_ok_C() {
        $sandbox = new LiuSandbox();
        $code = "#include <stdio.h>\nint main() {\n    printf(\"Hello sandbox\\n\");\n    return 0;\n}\n";
        $result = $sandbox->execute($code, 'C', NULL);
        $this->assertEquals(Sandbox::RESULT_SUCCESS, $result->result);
        $this->assertEquals("Hello sandbox\n", $result->output);
        $this->assertEquals(0, $result->signal);
        $this->assertEquals('', $result->cmpinfo);
        $sandbox->close();
    }

    // Test the liu sandbox will allow opening, writing and reading in the current dir
    public function test_liu_sandbox_fileio_in_cwd() {
        $sandbox = new LiuSandbox();
        $code =
"#include <stdio.h>
 int main() {
     FILE* f = fopen(\"junk\", \"w\");
     char buff[20];
     fputs(\"stuff\\n\", f);
     fclose(f);
     f = fopen(\"junk\", \"r\");
     fgets(buff, 20, f);
     fclose(f);
     printf(\"%s\", buff);
}
";
        $result = $sandbox->execute($code, 'C', NULL);
        $this->assertEquals(Sandbox::RESULT_SUCCESS, $result->result);
        $this->assertEquals("stuff\n", $result->output);
        $this->assertEquals(0, $result->signal);
        $this->assertEquals('', $result->cmpinfo);
        $sandbox->close();
    }


    // Test the liu sandbox will not allow opening, writing and reading in /tmp
    public function test_liu_sandbox_fileio_bad() {
        $sandbox = new LiuSandbox();
        $code =
"#include <stdio.h>
 int main() {
     FILE* f = fopen(\"/tmp/junk\", \"w\");
     char buff[20];
     fputs(\"stuff\\n\", f);
     fclose(f);
     f = fopen(\"/tmp/junk\", \"r\");
     fgets(buff, 20, f);
     fclose(f);
     printf(\"%s\\n\", buff);
}
";
        $result = $sandbox->execute($code, 'C', NULL);
        $this->assertEquals(Sandbox::RESULT_ILLEGAL_SYSCALL, $result->result);
        $sandbox->close();
    }


    // Test the liu sandbox with a timeout error
    public function test_liu_sandbox_timeout() {
        $sandbox = new LiuSandbox();
        $code = "#include <stdio.h>
int main() {
  while(1) {}
}
";
        $result = $sandbox->execute($code, 'C', NULL);
        $this->assertEquals(Sandbox::RESULT_TIME_LIMIT, $result->result);
        $this->assertEquals('', $result->output);
        $this->assertEquals('', $result->stderr);
        $this->assertTrue($result->signal == 18 || $result->signal == 10);
        $this->assertEquals('', $result->cmpinfo);
        $sandbox->close();
    }

    // Test the liu sandbox with a memory limit error
    public function test_liu_sandbox_memlimit() {
        $sandbox = new LiuSandbox();
        $code = "#include <stdlib.h>
int main() {
  char* p;
  while(1) { p = malloc(1000); p[0] = 0;}
}
";
        $result = $sandbox->execute($code, 'C', NULL);
        $this->assertEquals(Sandbox::RESULT_MEMORY_LIMIT, $result->result);
        $this->assertEquals('', $result->output);
        $this->assertEquals('', $result->stderr);
        $this->assertEquals('', $result->cmpinfo);
        $sandbox->close();
    }

    private function delTree($dir) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

}

?>
