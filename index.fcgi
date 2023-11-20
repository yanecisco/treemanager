#!/usr/bin/perl

use strict;
#use CGI::Fast;
use FCGI;
use Data::Dumper;
use JSON;
use Template;
use File::Slurp;
use DBI;

# globals
our %_CONFIG;
our $__DB;

# template
my $tmpl_config = {
    INCLUDE_PATH => 'tmpl/',
};
my $template = Template->new($tmpl_config);

# dumper
$Data::Dumper::Sortkeys = 1;

# fastcgi
$_CONFIG{fcgi_socket} //= 'localhost:18088';
$_CONFIG{fcgi_queue} //= 10;
my $socket = FCGI::OpenSocket($_CONFIG{fcgi_socket}, $_CONFIG{fcgi_queue});

my $FCGI = FCGI::Request(
    \*STDIN,
    \*STDOUT,
    new IO::Handle, #\*STDERR,
    \%ENV,
    $socket,
    1
);

# config
do('./config.pl') or die "no config";

# db
db_connect();

# main loop
while($FCGI->Accept() >= 0) { #my $q = new CGI::Fast) { #$request->Accept() >= 0) {

    my %_POST;
    my %_REQUEST;
    my %_GET; #not used yet

    my $page = $ENV{REQUEST_URI};
    $page =~ s/^\///;

    # get data
    # not used ATM

    # post data
    if ($ENV{'REQUEST_METHOD'} eq 'POST') {
        read(STDIN, my $postdata, $ENV{'CONTENT_LENGTH'});
        foreach my $druhy (split(/&/, $postdata)) {
            my ($jmeno, $hodnota) = split(/=/, $druhy);
            $hodnota =~ tr/+/ /;
            $hodnota =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
            $_POST{$jmeno} = $hodnota;
            $_REQUEST{$jmeno} = $hodnota;
        }
    }

    if ($page eq '') { #default page

        my $obj = read_db();

        render_page(
            obj => $obj,
        );

    } elsif ($page eq 'add') { #add page


        # do some magic
        #
        my $error;
        my $newid = $_POST{pid};

        my $obj = read_db();

        my %exist = %{$obj->{exist}};

        if ($newid =~ /^\s*(\d+)\s*$/) {
            if ($exist{$1}) {

                db_do("INSERT INTO tree SET parent_id = ?", $1);

                $obj = read_db(); # re-read db

            } else {
                $error = "Unable to extend id $1 - that doesn't exist";
            }
        } else {
            $error = "malformed ID";
        }

        render_page(
            obj => $obj,
            error => $error,
        );

    } elsif ($page =~ /static\/([a-zA-Z0-9]+).css/) { # static css files
        print "Content-type: text/css\n\n";
        print read_file("static/$1.css"); # TODO: pipeline this

    } else {
        print "Status: 404 Not found\n\n";
    }

    $FCGI->Finish();
    $FCGI->Flush();
}

sub read_db {
    my @data = db_multirow("SELECT * FROM tree"); # fastest for our demo case
    # we can also call some own-created stored method
    # or walk nodes by nodes (this is very slow)

    my %exist;
    my %parents;
    my $index = 0;
    foreach(@data) {
        my $id = $_->{id};
        my $parent_id = $_->{parent_id} // 0;

        push(@{ $parents{$parent_id} }, $index);

        $exist{$id} = 1;

        $index++;
    }

    my $in;
    $in->{exist} = \%exist;
    $in->{parents} = \%parents;
    $in->{data} = \@data;

    return $in;

}


sub render_page {
    my %in = @_;

    my %parents = %{ $in{obj}->{parents} // {} };
    my @data = @{ $in{obj}->{data} // [] };

    print "Content-type: text/html\n\n";
    #print "Content-type: text/plain\n\n";

    my $depth = 0; # current depth

    my @table;

    print STDERR Dumper(\%parents);
    # walk from top of the tree
    my @queue = @{ $parents{0} // [] };
    while(1) {

        print STDERR "queue: @queue\n";
        my @ch;
        my @q2;
        while(defined(my $id = shift @queue)) {
            print STDERR "shift $id\n";
            next if not defined $id;

            my @x = @{ $parents{$id} // [] };
            push(@ch, map { $data[$_] } @x);
            push(@q2, map { $data[$_]->{id} } @x);
        }

        print STDERR "d=$depth ch: @ch\n";

        if (@ch) {
            my @childs = map {
                #my $y = $data[$_];
                {
                    content => sprintf("[P: %d ID: %d]", $_->{parent_id}, $_->{id})
                }
            } @ch;

            push(@table, {
                    depth => $depth,
                    childs => \@childs
                }
            );
        }

        @queue = @q2;
        $depth++;
        last unless @ch;
        last if $depth > 50;
    }

    print STDERR Dumper(\@table);

    my $vars = {
        table => \@table,
        error => $in{error},
        #debug1 => Dumper(\%ENV),
        debug2 => Dumper({ parents => \%parents, data => \@data}),
    };

    $template->process('index.tt2', $vars) || die "$!";
}

# move to a separate package

sub db_connect {
    my %cf = (
        map { $_ => $_CONFIG{$_} }
        qw/
            db_type 
            db_database 
            db_host 
            db_user 
            db_pass 
        /,
    );

    my $h = DBI->connect_cached(
        join(":","DBI",$cf{'db_type'},$cf{'db_database'},$cf{'db_host'}),
        $cf{'db_user'},
        $cf{'db_pass'},
    );

    if ($h) {
        $__DB = $h;
    }

}

sub db_multirow {
    my $DB = $__DB;

    my $st = shift;

    my $q = $DB->prepare_cached($st);
    if (!$q) {
        die "db error: '$st'";
    }

    my $res = $q->execute(@_);

    my @result;
    while (my $row = $q->fetchrow_hashref) { 
        push(@result, $row); 
    };
    $q->finish();

    return @result;
}

sub db_do {
    my $DB = $__DB;

    my ($a) = shift;
    my $sql = $DB->prepare($a); # || warn $a;
    if (!$sql) {
        die("Error: ".$DB->errstr);
        return;
    }
    return $sql->execute(@_);
}

1;
